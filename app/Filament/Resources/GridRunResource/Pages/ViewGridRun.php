<?php

namespace App\Filament\Resources\GridRunResource\Pages;

use App\Filament\Resources\GridRunResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;

class ViewGridRun extends ViewRecord
{
    protected static string $resource = GridRunResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Meta')->schema([
                TextEntry::make('id')->label('Id')
                    ->getStateUsing(fn ($record) => self::toString($record?->id)),

                TextEntry::make('trace_id')->label('Trace id')->copyable()
                    ->getStateUsing(fn ($record) => self::toString($record?->trace_id)),

                TextEntry::make('symbol')->label('Symbol')
                    ->getStateUsing(fn ($record) => self::toString($record?->symbol)),

                TextEntry::make('mode')->label('Mode')
                    ->badge()
                    ->color(fn ($record) => ($record?->mode === 'buy') ? 'success' : (($record?->mode === 'sell') ? 'danger' : 'primary'))
                    ->getStateUsing(fn ($record) => self::toString($record?->mode)),

                TextEntry::make('levels')->label('Levels')
                    ->getStateUsing(fn ($record) => self::toString($record?->levels)),

                TextEntry::make('step_pct')->label('Step pct')
                    ->getStateUsing(fn ($record) => self::fmtDecimal($record?->step_pct, 3)),

                TextEntry::make('budget_irt')->label('Budget irt')
                    ->getStateUsing(fn ($record) => self::fmtInt($record?->budget_irt)),

                TextEntry::make('simulation')->label('Simulation')
                    ->badge()
                    ->color(fn ($record) => (bool) $record?->simulation ? 'gray' : 'success')
                    ->getStateUsing(fn ($record) => (bool) $record?->simulation ? 'yes' : 'no'),

                TextEntry::make('status')->label('Status')
                    ->badge()
                    ->color(fn ($record) => match (self::toString($record?->status)) {
                        'ok'      => 'success',
                        'running' => 'warning',
                        'failed'  => 'danger',
                        default   => 'gray',
                    })
                    ->getStateUsing(fn ($record) => self::toString($record?->status)),

                TextEntry::make('started_at')->label('Started at')->dateTime(),
                TextEntry::make('finished_at')->label('Finished at')->dateTime(),
            ])->columns(3),

            // ---------- PLAN ----------
            Section::make('Plan')->collapsible()->schema([
                TextEntry::make('plan_json')->label('plan_json')->columnSpanFull()
                    ->getStateUsing(fn ($record) => self::renderPlanMd($record?->plan_json))
                    ->markdown()
                    ->copyable()
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    ->visible(fn ($record) => filled($record?->plan_json)),
            ]),

            // ---------- DIFF ----------
            Section::make('Diff')->collapsible()->schema([
                TextEntry::make('diff_json')->label('diff_json')->columnSpanFull()
                    ->getStateUsing(fn ($record) => self::renderDiffMd($record?->diff_json))
                    ->markdown()
                    ->copyable()
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    ->visible(fn ($record) => filled($record?->diff_json)),
            ]),

            // ---------- SUMMARY ----------
            Section::make('Summary')->collapsible()->schema([
                TextEntry::make('summary_json')->label('summary_json')->columnSpanFull()
                    ->getStateUsing(fn ($record) => self::renderSummaryMd($record?->summary_json))
                    ->markdown()
                    ->copyable()
                    ->extraAttributes(['class' => 'font-mono text-xs'])
                    ->visible(fn ($record) => filled($record?->summary_json)),
            ]),
        ]);
    }

    /** ───────── Helpers to render safe strings ───────── */

    protected static function toString($value): string
    {
        try {
            if (is_array($value) || is_object($value)) {
                return json_encode(json_decode(json_encode($value), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            return $value === null ? '' : (string) $value;
        } catch (\Throwable $e) {
            Log::error('ViewGridRun toString error', [
                'type' => is_object($value) ? get_class($value) : gettype($value),
                'msg'  => $e->getMessage(),
            ]);
            return '';
        }
    }

    protected static function fmtInt($v): string
    {
        return is_numeric($v) ? number_format((int) $v) : self::toString($v);
    }

    protected static function fmtDecimal($v, int $dec = 3): string
    {
        if (!is_numeric($v)) {
            return self::toString($v);
        }
        $s = number_format((float) $v, $dec, '.', '');
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    /** Normalize any json-ish input (cast/array/json-string/stdClass) to array. */
    protected static function normalizeArray($state): array
    {
        if (is_array($state)) return $state;
        if (is_object($state)) return json_decode(json_encode($state), true) ?? [];
        if (is_string($state)) {
            $decoded = json_decode($state, true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }
        return [];
    }

    /** Build a small Markdown table from key => value pairs. */
    protected static function mdKeyValTable(array $rows, array $labels = []): string
    {
        $out = [];
        $out[] = '| Key | Value |';
        $out[] = '| --- | ----- |';

        foreach ($rows as $k => $v) {
            $label = $labels[$k] ?? (string) $k;

            if (is_array($v) || is_object($v)) {
                $v = json_encode(self::normalizeArray($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif ($v === true) {
                $v = 'true';
            } elseif ($v === false) {
                $v = 'false';
            } elseif ($v === null) {
                $v = '';
            }

            $out[] = '| ' . str_replace('|', '\|', (string) $label) . ' | ' . str_replace('|', '\|', (string) $v) . ' |';
        }

        return implode("\n", $out);
    }

    protected static function renderPlanMd($state): string
    {
        $data = self::normalizeArray($state);

        // بالا (اطلاعات کلی)
        $meta = [
            'symbol'               => $data['symbol']            ?? '',
            'mid'                  => $data['mid']               ?? '',
            'levels'               => $data['levels']            ?? '',
            'per_side'             => $data['per_side']          ?? '',
            'mode'                 => $data['mode']              ?? '',
            'step_pct'             => $data['step_pct']          ?? '',
            'tick'                 => $data['tick']              ?? '',
            'qty_decimals'         => $data['qty_decimals']      ?? '',
            'budget_irt'           => $data['budget_irt']        ?? '',
            'min_order_value_irt'  => $data['min_order_value_irt'] ?? '',
            'fee_bps'              => $data['fee_bps']           ?? '',
            'estimated_notional'   => $data['estimated_notional']?? '',
            'estimated_fee_irt'    => $data['estimated_fee_irt'] ?? '',
            'collapsed_levels'     => $data['collapsed_levels']  ?? '',
            'below_min_orders'     => $data['below_min_orders']  ?? '',
            'ts'                   => $data['ts']                ?? '',
        ];

        $md  = "### Overview\n";
        $md .= self::mdKeyValTable($meta);
        $md .= "\n\n";

        // آیتم‌ها
        $items = $data['items'] ?? [];
        if (is_array($items) && count($items)) {
            $md .= "### Items\n";
            $md .= "| # | Side | Price | Quantity | Notional | Below Min |\n";
            $md .= "| - | ---- | -----:| -------: | -------: | :-------: |\n";
            foreach ($items as $i => $it) {
                $side     = $it['side']     ?? '';
                $price    = $it['price']    ?? '';
                $qty      = $it['quantity'] ?? ($it['qty'] ?? '');
                $notional = $it['notional'] ?? '';
                $below    = isset($it['below_min']) ? ($it['below_min'] ? 'yes' : 'no') : '';
                $md .= sprintf(
                    "| %d | %s | %s | %s | %s | %s |\n",
                    $i + 1,
                    self::toString($side),
                    self::toString($price),
                    self::toString($qty),
                    self::toString($notional),
                    $below
                );
            }
        }

        return $md;
    }

    protected static function renderDiffMd($state): string
    {
        $data  = self::normalizeArray($state);
        $md    = "### Counters\n";
        $counts = $data['counts'] ?? [];
        $md   .= self::mdKeyValTable($counts);
        $md   .= "\n\n";

        $lists = [
            'to_place'  => ['#','Symbol','Side','Price','Quantity','Notional','Reason'],
            'to_cancel' => ['#','Order Id','Reason'],
            'keep'      => ['#','Order Id'],
        ];

        foreach ($lists as $key => $header) {
            $rows = $data[$key] ?? [];
            if (!is_array($rows) || !count($rows)) {
                continue;
            }

            $md .= '### ' . ucfirst(str_replace('_', ' ', $key)) . "\n";
            $md .= '| ' . implode(' | ', $header) . " |\n";
            $md .= '| ' . implode(' | ', array_map(fn() => '---', $header)) . " |\n";

            foreach ($rows as $i => $row) {
                if ($key === 'to_place') {
                    $md .= sprintf(
                        "| %d | %s | %s | %s | %s | %s | %s |\n",
                        $i + 1,
                        self::toString($row['symbol']  ?? ''),
                        self::toString($row['side']    ?? ''),
                        self::toString($row['price']   ?? ''),
                        self::toString($row['quantity']?? ''),
                        self::toString($row['notional']?? ''),
                        self::toString($row['reason']  ?? '')
                    );
                } elseif ($key === 'to_cancel') {
                    $md .= sprintf(
                        "| %d | %s | %s |\n",
                        $i + 1,
                        self::toString($row['exchange_order_id'] ?? ($row['id'] ?? '')),
                        self::toString($row['reason'] ?? '')
                    );
                } else { // keep
                    $md .= sprintf(
                        "| %d | %s |\n",
                        $i + 1,
                        self::toString($row['exchange_order_id'] ?? ($row['id'] ?? ''))
                    );
                }
            }

            $md .= "\n";
        }

        return $md;
    }

    protected static function renderSummaryMd($state): string
    {
        $data = self::normalizeArray($state);
        if (!$data) {
            return '';
        }

        // اگر summary ساده باشد مثل: {"placed":0,"canceled":0,"simulation":true}
        return self::mdKeyValTable($data);
    }
}
