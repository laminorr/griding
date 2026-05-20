# WS Health Indicator – Implementation Summary

## 1. Files created / modified

| Role | Path |
|------|------|
| **Service** | `app/Services/WebSocketHealthService.php` |
| **Topbar pill view** | `resources/views/filament/components/ws-health-pill.blade.php` |
| **Panel provider** (modified) | `app/Providers/Filament/AdminPanelProvider.php` |
| **Dashboard widget** | `app/Filament/Widgets/WebSocketHealthWidget.php` |
| **Widget view** | `resources/views/filament/widgets/web-socket-health-widget.blade.php` |

---

## 2. `getStatus()` return shape

```php
// Example when all symbols are fresh (age ≤ 30 s)
[
    'status'             => 'active',
    'label'              => 'فعال',
    'symbols'            => [
        'BTCIRT' => [
            'status'      => 'active',
            'price'       => 137386933,
            'ts'          => 1716200000,
            'age_seconds' => 4,
        ],
        'ETHIRT' => [
            'status'      => 'active',
            'price'       => 8521000,
            'ts'          => 1716200001,
            'age_seconds' => 3,
        ],
        'USDTIRT' => [
            'status'      => 'stale',
            'price'       => 68250,
            'ts'          => 1716199958,
            'age_seconds' => 47,
        ],
    ],
    'oldest_age_seconds' => 47,
    'newest_age_seconds' => 3,
    'checked_at'         => 1716200004,
]

// When a symbol has no cache entry
'BTCIRT' => [
    'status'      => 'down',
    'price'       => null,
    'ts'          => null,
    'age_seconds' => null,
]
```

Thresholds: age ≤ 30 s → **active**, 31–120 s → **stale**, > 120 s or missing → **down**.  
Overall: all active → `active`; any active/stale → `stale`; all down → `down`.  
Result is cached under `ws:health:cached` for 5 seconds.

---

## 3. Topbar pill

- **Render hook used**: `'panels::topbar.end'` (string form, consistent with the existing hooks in `AdminPanelProvider`).
- The hook closure catches all `\Throwable`; on error it passes `$status = null` to the view.
- View renders `<a href="/admin" …>` with:
  - 🟢 `bg-green-500` + "WS فعال" when active
  - 🟡 `bg-yellow-500` + "WS تأخیر" when stale
  - 🔴 `bg-red-500` + "WS قطع" when down
  - ⚪ `bg-gray-400` + "WS نامشخص" on service error
- Native `title` attribute shows "آخرین به‌روزرسانی: N ثانیه پیش" (from `newest_age_seconds`).
- `php -l` passes; `php artisan route:list` registers 16 admin routes cleanly.

---

## 4. Dashboard widget

- Class: `App\Filament\Widgets\WebSocketHealthWidget` (extends `Filament\Widgets\Widget`)
- `$sort = -1` → renders above `BotStatusWidget` ($sort=1) and `PerformanceChartWidget` ($sort=2)
- `$columnSpan = 'full'`, `$pollingInterval = '10s'`
- Data fetched in `getViewData()`, wrapped in `try/catch (\Throwable)`.
- Widget auto-discovered from `app/Filament/Widgets/` by `AdminPanelProvider::discoverWidgets()`.

### Widget visual layout

```
┌─────────────────────────────────────────────────────────────┐
│  وضعیت WebSocket                              🔴 قطع        │
├──────────┬──────────────┬──────────────┬───────────────────-┤
│ Symbol   │  وضعیت       │  قیمت آخر    │  عمر داده          │
├──────────┼──────────────┼──────────────┼────────────────────┤
│ BTCIRT   │ 🔴 قطع        │  —           │  —                 │
│ ETHIRT   │ 🟡 تأخیر     │  8,521,000   │  47 ثانیه          │
│ USDTIRT  │ 🟢 فعال      │  68,250      │  3 ثانیه           │
├──────────┴──────────────┴──────────────┴────────────────────┤
│ Last checked: 2 seconds ago                                  │
└─────────────────────────────────────────────────────────────┘
```

Rows sorted: down → stale → active; within each group alphabetically by symbol.

---

## 5. Decisions made independently

| Decision | Rationale |
|----------|-----------|
| Render hook key: `'panels::topbar.end'` (string) | Consistent with existing hook style in `AdminPanelProvider` |
| Widget `$sort = -1` | Ensures it floats above `BotStatusWidget` (sort=1) without needing to renumber existing widgets |
| `\Throwable` in the pill hook closure (not `\Exception`) | Guards against fatal errors and PHP 8 `Error` subclasses |
| `Number::format($price, 0)` for prices | Matches the convention used in `BotStatusWidget` |
| `$pollingInterval = '10s'` | As specified; Filament adds `wire:poll.10s` to the outer Livewire element |
| Cache TTL for `ws:health:cached`: 5 s | Prevents N redundant `Cache::get` calls during a single page render with multiple components |
| `getViewData()` override (not `render()`) | Filament v3 `Widget` base exposes this hook; avoids conflict with Livewire's render lifecycle |
