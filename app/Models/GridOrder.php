<?php

namespace App\Models;

use App\Exceptions\DefinitiveInvalidArgumentRejection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GridOrder extends Model
{
    protected $table = 'grid_orders';

    protected $fillable = [
        'client_order_id','exchange_order_id','status',
        'price_irt','amount','matched','unmatched','raw_json',
        'bot_config_id','price','type','nobitex_order_id','paired_order_id','filled_at',
        'original_amount','filled_amount','remaining_amount','average_fill_price','last_fill_at',
        'role',
        'reconcile_attempts','reconcile_not_found_count','reconcile_last_attempt_at',
        // Fee model Phase 3 — per-order fee capture (see FeeModel::fillFields).
        'fee_amount','fee_currency','fee_asset','fee_source','fee_quote','avg_fill_price','net_base_delta',
        // Fee model Phase 4 — base_dust change caused by this exit row (ExitSizer).
        'exit_dust_delta',
        // Fee model Phase 5 — definitive rejections / blocked exits.
        'last_error_code','last_error_message','exit_state','exit_blocked_reason','exit_blocked_at',
    ];

    protected $casts = [
        'amount'             => 'decimal:8',
        'matched'            => 'decimal:8',
        'unmatched'          => 'decimal:8',
        'raw_json'           => 'array',
        // NOTE: `price` and `average_fill_price` are DECIMAL(20,0) columns and
        // are deliberately NOT cast here. They were previously cast to
        // 'integer' (Phase 4 / Phase 9), which coerces the value back to a
        // native PHP int on read — a 32-bit-overflow hazard for ~20-digit IRT
        // values and exactly the type the write-side mutators below exist to
        // avoid. Leaving them uncast keeps the full decimal string intact end
        // to end; upstream callers run through the bcmath Money helper and
        // treat these as decimal strings. (Phase 10, Step 7.)
        'filled_at'          => 'datetime',
        'original_amount'    => 'decimal:8',
        'filled_amount'      => 'decimal:8',
        'remaining_amount'   => 'decimal:8',
        'last_fill_at'       => 'datetime',
        'reconcile_attempts'        => 'integer',
        'reconcile_not_found_count' => 'integer',
        'reconcile_last_attempt_at' => 'datetime',
        // DECIMAL(36,18) — read back as exact decimal strings.
        'fee_amount'         => 'decimal:18',
        'fee_quote'          => 'decimal:18',
        'avg_fill_price'     => 'decimal:18',
        'net_base_delta'     => 'decimal:18',
        'exit_dust_delta'    => 'decimal:18',
        'exit_blocked_at'    => 'datetime',
    ];

    /**
     * Force the price to a string so it is bound as PDO::PARAM_STR.
     *
     * Phase 10, Step 7 — upstream calculations were migrated to the bcmath
     * Money helper and now hand us decimal strings natively, so this mutator no
     * longer *converts* anything in the normal flow. It is retained as a
     * defensive normalization + validation guard because the overflow risk
     * lives in the PDO layer, not in our code: Laravel's Connection::bindValues
     * binds a native PHP int as PDO::PARAM_INT, which truncates a value above
     * the signed 32-bit ceiling on 32-bit / emulated-prepare drivers
     * (e.g. 101500000000 -> -1579215104). Passing a string keeps the binding as
     * PARAM_STR so the full DECIMAL(20,0) value is stored intact.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['price'] = $this->normalizeDecimalString('price', $value);
    }

    public function setAverageFillPriceAttribute($value): void
    {
        // Same rationale as setPriceAttribute. Nullable column, so null passes
        // through untouched.
        $this->attributes['average_fill_price'] = $value === null
            ? null
            : $this->normalizeDecimalString('average_fill_price', $value);
    }

    /**
     * Normalize an incoming DECIMAL(20,0) value into a safe string binding.
     *
     * - Rejects arrays/objects (never valid for a numeric column).
     * - Emits a debug regression signal if a native PHP int larger than
     *   PHP_INT_MAX / 2 arrives, which means some caller bypassed the bcmath
     *   Money pipeline and is one 32-bit driver away from silently truncating.
     * - Returns the value as a string so it binds as PARAM_STR.
     */
    private function normalizeDecimalString(string $column, $value): string
    {
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException(
                "GridOrder::{$column} expects a scalar decimal value, " . gettype($value) . ' given.'
            );
        }

        if (is_int($value) && abs($value) > PHP_INT_MAX / 2) {
            Log::debug('GRID_ORDER_LARGE_NATIVE_INT_BINDING', [
                'column' => $column,
                'value'  => $value,
                'note'   => 'Native int on a DECIMAL(20,0) column — expected a bcmath string. Possible upstream regression.',
            ]);
        }

        return (string) $value;
    }

    /**
     * Validation and guards
     */
    protected static function booted(): void
    {
        static::saving(function (GridOrder $order) {
            // Validate price is positive and within DECIMAL(20,0) limits
            if (!is_numeric($order->price) || $order->price <= 0) {
                throw new \InvalidArgumentException("Invalid price on GridOrder: {$order->price}");
            }

            // Check doesn't exceed decimal(20,0) limit (10^20 - 1)
            if (strlen((string)$order->price) > 20) {
                throw new \InvalidArgumentException("Price exceeds DECIMAL(20,0) limit: {$order->price}");
            }
        });
    }

    /**
     * The clientOrderId sent to Nobitex for an intent row — the ONE place the
     * format lives.
     *
     * Format (v2): "g{botId}-{gridOrderRowId}", e.g. "g47-123456".
     *
     *  - Unique per order intent, forever: the row id is the primary key of
     *    grid_orders, so two intents can never share an id — not even two
     *    exits that land on the same price (the old price-derived
     *    'grid:{bot}:{SYMBOL}:{side}:{price}' scheme collided there and either
     *    DEDUP_SKIPped the second fill forever or hit the UNIQUE index).
     *  - Stable across retries of the SAME intent: a retry reuses the same row,
     *    so it sends the same id (idempotency / reconciler lookup).
     *  - Always valid for Nobitex: 'g' + bot id (≤ 6 digits) + '-' + row id
     *    (≤ 20 digits) is at most 28 chars of [A-Za-z0-9-]. Asserted below; a
     *    violation is impossible for a persisted row and is refused locally.
     *
     * Rows created before v2 keep their legacy 'grid:…' ids untouched; those
     * ids never start with 'g{digit}', so the two schemes cannot collide.
     *
     * @throws DefinitiveInvalidArgumentRejection 'LocalValidation' if the row is
     *         not persisted or the result would violate the Nobitex rule.
     */
    public static function clientOrderIdFor(GridOrder $row): string
    {
        // Raw attributes, not the casts: the primary-key 'int' cast would
        // silently saturate an out-of-range id instead of refusing it.
        $raw   = $row->getAttributes();
        $botId = $raw['bot_config_id'] ?? null;
        $rowId = $raw[$row->getKeyName()] ?? null;

        if (! self::isPositiveIntId($botId) || ! self::isPositiveIntId($rowId)) {
            throw DefinitiveInvalidArgumentRejection::withCode('LocalValidation', sprintf(
                'clientOrderIdFor: row must be persisted with a bot (bot_config_id=%s, id=%s)',
                var_export($botId, true),
                var_export($rowId, true)
            ));
        }

        $id = 'g' . (string) $botId . '-' . (string) $rowId;

        if (! self::isValidNobitexClientOrderId($id)) {
            throw DefinitiveInvalidArgumentRejection::withCode(
                'LocalValidation',
                "clientOrderIdFor produced an id Nobitex would reject: {$id}"
            );
        }

        return $id;
    }

    /**
     * Intent row FIRST, id SECOND (send happens THIRD, in the caller, after
     * this commits). Creates the row with client_order_id NULL, then stamps
     * clientOrderIdFor(row) in the same transaction, so a committed intent row
     * always carries its final id and no row ever exists with a different one.
     * Nests safely (savepoint) inside a caller's open transaction.
     *
     * @param array<string,mixed> $attributes
     */
    public static function createIntent(array $attributes): self
    {
        return DB::transaction(function () use ($attributes): self {
            $attributes['client_order_id'] = null;

            /** @var self $row */
            $row = static::create($attributes);
            $row->forceFill(['client_order_id' => self::clientOrderIdFor($row)])->save();

            return $row;
        });
    }

    /** Digit-only positive integer id (int or numeric string from the driver). */
    private static function isPositiveIntId(mixed $v): bool
    {
        if (is_int($v)) {
            return $v > 0;
        }

        return is_string($v) && $v !== '' && ctype_digit($v) && ltrim($v, '0') !== '';
    }

    /**
     * Documented Nobitex constraint on the clientOrderId parameter
     * (apidocs.nobitex.ir, spot + margin order endpoints): at most 32
     * characters, matching ^[A-Za-z0-9-]+$ (ASCII letters, digits and the
     * hyphen only), unique among the user's OPEN orders.
     *
     * Pure predicate — the single source of truth for "would Nobitex accept
     * this clientOrderId?". Enforced at generation (clientOrderIdFor) and at
     * the send boundary (CreateOrderDto::toApiPayload,
     * NobitexService::placeOrder), which refuse an invalid id with
     * DefinitiveInvalidArgumentRejection('LocalValidation') before any HTTP call.
     */
    public static function isValidNobitexClientOrderId(string $clientOrderId): bool
    {
        // \A…\z rather than ^…$: '$' also matches before a trailing "\n".
        return $clientOrderId !== ''
            && strlen($clientOrderId) <= 32
            && preg_match('/\A[A-Za-z0-9-]+\z/', $clientOrderId) === 1;
    }

    public function botConfig(): BelongsTo
    {
        return $this->belongsTo(BotConfig::class, 'bot_config_id');
    }
}
