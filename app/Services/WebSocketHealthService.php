<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class WebSocketHealthService
{
    private const ACTIVE_THRESHOLD = 30;
    private const STALE_THRESHOLD  = 120;

    private const LABELS = [
        'active' => 'فعال',
        'stale'  => 'تأخیر',
        'down'   => 'قطع',
    ];

    public function getStatus(): array
    {
        return Cache::remember('ws:health:cached', 5, fn () => $this->compute());
    }

    private function compute(): array
    {
        $symbols = array_values(array_filter(
            array_map(
                fn ($s) => strtoupper(trim((string) $s)),
                (array) config('trading.exchange.allowed_symbols', [])
            ),
            fn ($s) => $s !== ''
        ));

        $symbolStatuses = [];
        $oldestAge      = null;
        $newestAge      = null;

        foreach ($symbols as $symbol) {
            $cached = Cache::get(MarketDataLayer::CACHE_PREFIX_PRICE . $symbol);

            if (!is_array($cached) || !isset($cached['ts'])) {
                $symbolStatuses[$symbol] = [
                    'status'      => 'down',
                    'price'       => null,
                    'ts'          => null,
                    'age_seconds' => null,
                ];
                continue;
            }

            $age   = time() - (int) $cached['ts'];
            $price = isset($cached['price']) ? (int) $cached['price'] : null;

            $symStatus = match (true) {
                $age <= self::ACTIVE_THRESHOLD => 'active',
                $age <= self::STALE_THRESHOLD  => 'stale',
                default                        => 'down',
            };

            $symbolStatuses[$symbol] = [
                'status'      => $symStatus,
                'price'       => $price,
                'ts'          => (int) $cached['ts'],
                'age_seconds' => $age,
            ];

            if ($oldestAge === null || $age > $oldestAge) {
                $oldestAge = $age;
            }
            if ($newestAge === null || $age < $newestAge) {
                $newestAge = $age;
            }
        }

        $allStatuses = array_column($symbolStatuses, 'status');
        $nonActive   = array_filter($allStatuses, fn ($s) => $s !== 'active');

        if (count($allStatuses) > 0 && count($nonActive) === 0) {
            $overall = 'active';
        } elseif (in_array('active', $allStatuses, true) || in_array('stale', $allStatuses, true)) {
            $overall = 'stale';
        } else {
            $overall = 'down';
        }

        return [
            'status'             => $overall,
            'label'              => self::LABELS[$overall],
            'symbols'            => $symbolStatuses,
            'oldest_age_seconds' => $oldestAge,
            'newest_age_seconds' => $newestAge,
            'checked_at'         => time(),
        ];
    }
}
