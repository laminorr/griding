<?php
declare(strict_types=1);

namespace App\DTOs;

final class WalletsDto
{
    /** @var array<string, BalanceDto> */
    public array $wallets;

    /** @param array<string, BalanceDto> $wallets */
    public function __construct(array $wallets = [])
    {
        foreach ($wallets as $w) {
            if (!$w instanceof BalanceDto) {
                throw new \InvalidArgumentException('WalletsDto: all items must be BalanceDto');
            }
        }
        $this->wallets = $wallets;
    }

    public static function fromApi(array $payload): self
    {
        $rows = [];

        // حالت استاندارد: {"wallets": [...]}
        if (isset($payload['wallets']) && is_array($payload['wallets'])) {
            $rows = $payload['wallets'];

        // برخی پاسخ‌ها: {"data": {"wallets": [...]}}
        } elseif (isset($payload['data']) && is_array($payload['data'])
            && isset($payload['data']['wallets']) && is_array($payload['data']['wallets'])) {
            $rows = $payload['data']['wallets'];

        // اگر آرایهٔ خطی wallets مستقیماً پاس شده باشد
        } elseif (is_array($payload) && self::isList($payload)) {
            $rows = $payload;
        }

        $wallets = [];
        foreach ($rows as $row) {
            try {
                $b = BalanceDto::fromApi($row);
                if ($b->currency !== '') {
                    $wallets[$b->currency] = $b; // map by currency (rls, btc, ...)
                }
            } catch (\Throwable $e) {
                // skip bad row
            }
        }

        return new self($wallets);
    }

    /** تشخیص لیست بودن آرایه (بدون استفاده از array_is_list) */
    private static function isList(array $arr): bool
    {
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }

    public function find(string $currency): ?BalanceDto
    {
        $currency = strtolower($currency);
        return $this->wallets[$currency] ?? null;
    }

    public function hasAvailable(string $currency, string $minAmount, int $scale = 8): bool
    {
        $w = $this->find($currency);
        return $w?->hasAvailableGreaterThan($minAmount, $scale) ?? false;
    }

    /** @return array<string, BalanceDto> */
    public function all(): array
    {
        return $this->wallets;
    }

    public function toArray(): array
    {
        $out = [];
        foreach ($this->wallets as $cur => $dto) {
            $out[$cur] = $dto->toArray();
        }
        return $out;
    }
}
