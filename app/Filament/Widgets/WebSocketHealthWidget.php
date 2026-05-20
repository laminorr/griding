<?php
declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\WebSocketHealthService;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;

class WebSocketHealthWidget extends Widget
{
    protected static string $view = 'filament.widgets.web-socket-health-widget';

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '10s';

    protected function getViewData(): array
    {
        try {
            $health  = app(WebSocketHealthService::class)->getStatus();
            $symbols = $health['symbols'] ?? [];

            $order = ['down' => 0, 'stale' => 1, 'active' => 2];
            uksort($symbols, function (string $ka, string $kb) use ($order, $symbols): int {
                $cmp = ($order[$symbols[$ka]['status']] ?? 3) <=> ($order[$symbols[$kb]['status']] ?? 3);
                return $cmp !== 0 ? $cmp : strcmp($ka, $kb);
            });

            // Pre-format prices for the view
            $formatted = [];
            foreach ($symbols as $sym => $info) {
                $formatted[$sym] = [
                    'status'      => $info['status'],
                    'price'       => $info['price'] !== null ? Number::format($info['price'], 0) : null,
                    'age_seconds' => $info['age_seconds'],
                ];
            }

            return [
                'rows'       => $formatted,
                'health'     => $health,
                'checkedAgo' => time() - ($health['checked_at'] ?? time()),
                'error'      => null,
            ];
        } catch (\Throwable) {
            return [
                'rows'       => [],
                'health'     => null,
                'checkedAgo' => 0,
                'error'      => 'WS health data unavailable',
            ];
        }
    }
}
