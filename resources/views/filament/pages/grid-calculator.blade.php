<x-filament-panels::page>
    @php
        // ---- Safe defaults to avoid "Undefined variable" & type issues ----
        $mode           = $mode           ?? 'پیش‌فرض';
        $lastUpdated    = $lastUpdated    ?? '-';
        $minOrderAmount = $minOrderAmount ?? '-';
        $errorMessage   = $errorMessage   ?? '-';
        $status         = $status         ?? 'موجود';

        // results → همیشه آرایهٔ ایمن
        if (!isset($results)) {
            $results = [];
        } elseif ($results instanceof \Illuminate\Support\Collection) {
            $results = $results->values()->all();
        } elseif ($results instanceof \Traversable) {
            $results = iterator_to_array($results, false);
        } elseif (!is_array($results)) {
            $results = [];
        }

        // summary/stats/activity → ایمن‌سازی
        $summary = (isset($summary) && is_array($summary)) ? $summary : [];
        $stats   = (isset($stats)   && is_array($stats))   ? $stats   : [];

        if (!isset($recentActivity)) {
            $recentActivity = [];
        } elseif ($recentActivity instanceof \Illuminate\Support\Collection) {
            $recentActivity = $recentActivity->values()->all();
        } elseif ($recentActivity instanceof \Traversable) {
            $recentActivity = iterator_to_array($recentActivity, false);
        } elseif (!is_array($recentActivity)) {
            $recentActivity = [];
        }

        $rowsCount  = is_countable($results) ? count($results) : 0;
        $hasResults = $rowsCount > 0;

        // Helpers
        $dg = function ($item, $key, $default='-') {
            return \Illuminate\Support\Arr::get(is_array($item) ? $item : (array) $item, $key, $default);
        };

        $fmtNum = function ($v, $dec = 0) {
            if ($v === null || $v === '' || !is_numeric($v)) return '-';
            return number_format((float) $v, $dec, '.', ',');
        };
    @endphp

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        {{-- ================= Page Header ================= --}}
        <header class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-sm p-5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                {{-- Icon --}}
                <div class="flex items-center justify-center w-14 h-14 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 text-white text-2xl shadow-md">
                    ⚛
                </div>

                <div>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white leading-tight">محاسبه‌گر استراتژی گرید</h1>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">محاسبات پیشرفته — شبیه‌سازی، تست استرس، و ابزارهای کمکی برای تصمیم‌گیری</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <span class="inline-flex items-center text-xs font-medium bg-gradient-to-r from-green-500 to-teal-400 text-white px-3 py-1 rounded-lg shadow-sm">
                    نسخهٔ مدرن
                </span>

                <div class="text-right text-xs text-gray-500 dark:text-gray-400">
                    <div>حالت: <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $mode }}</span></div>
                    <div class="mt-0.5">آخرین تغییر: <span class="text-gray-400">{{ $lastUpdated }}</span></div>
                </div>
            </div>
        </header>

        {{-- ================= AI / Tools Features ================= --}}
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @php
                $featureList = [
                    ['emoji'=>'🧠','title'=>'سوپر بهینه‌سازی AI','desc'=>'پارامترها را برای بیشینه‌سازی بازده تنظیم می‌کند.','accent'=>'from-blue-500 to-indigo-600'],
                    ['emoji'=>'🔮','title'=>'پیش‌بینی عصبی','desc'=>'پیشنهاد نرخ‌های هدف با استفاده از مدل‌های سری زمانی.','accent'=>'from-purple-500 to-pink-500'],
                    ['emoji'=>'📊','title'=>'تحلیل چند‌بازه','desc'=>'مقایسهٔ عملکرد در تایم‌فریم‌های مختلف.','accent'=>'from-gray-100 to-gray-100','muted'=>true],
                    ['emoji'=>'💥','title'=>'تست استرس','desc'=>'شبیه‌سازی ریزش و فشار بازار.','accent'=>'from-gray-100 to-gray-100','muted'=>true],
                ];
            @endphp

            @foreach($featureList as $f)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center
                                {{ ($f['muted'] ?? false) ? 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200' : 'text-white' }}
                                {{ ($f['muted'] ?? false) ? '' : 'bg-gradient-to-br ' . $f['accent'] }}">
                        <span class="text-xl">{{ $f['emoji'] }}</span>
                    </div>

                    <div class="flex-1">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $f['title'] }}</h3>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $f['desc'] }}</p>
                    </div>
                </div>
            @endforeach
        </section>

        {{-- ================= Main Form & Controls ================= --}}
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Left: Inputs Card --}}
            <div class="lg:col-span-2">
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-medium text-gray-900 dark:text-white">ورودی‌ها</h2>

                        <div class="flex items-center gap-2">
                            {{-- Reset button --}}
                            <button type="button" wire:click="resetInputs" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-sm border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 hover:shadow-sm transition">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 12a9 9 0 1015.5-6.36L21 7" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                بازنشانی
                            </button>

                            {{-- Calculate button --}}
                            <button type="button" wire:click="calculateGrid" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-lg shadow hover:from-blue-700 hover:to-indigo-700 transition">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 12h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                محاسبه سریع
                            </button>
                        </div>
                    </div>

                    {{-- Grid inputs --}}
                    <form wire:submit.prevent="calculateGrid" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {{-- Start Price --}}
                            <div class="flex flex-col">
                                <label for="start_price" class="text-sm font-medium text-gray-700 dark:text-gray-300">قیمت شروع</label>
                                <input id="start_price" type="number" step="0.0001" wire:model.defer="start_price"
                                    class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-400 transition" />
                                <p class="mt-1 text-xs text-gray-400">قیمت پایین محدودهٔ گرید</p>
                            </div>

                            {{-- End Price --}}
                            <div class="flex flex-col">
                                <label for="end_price" class="text-sm font-medium text-gray-700 dark:text-gray-300">قیمت پایان</label>
                                <input id="end_price" type="number" step="0.0001" wire:model.defer="end_price"
                                    class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-400 transition" />
                                <p class="mt-1 text-xs text-gray-400">قیمت بالای محدودهٔ گرید</p>
                            </div>

                            {{-- Grid Count --}}
                            <div class="flex flex-col">
                                <label for="grid_count" class="text-sm font-medium text-gray-700 dark:text-gray-300">تعداد گرید</label>
                                <input id="grid_count" type="number" step="1" min="1" wire:model.defer="grid_count"
                                    class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-400 transition" />
                                <p class="mt-1 text-xs text-gray-400">چند ستون سفارش می‌خواهید؟</p>
                            </div>

                            {{-- Total Investment --}}
                            <div class="flex flex-col">
                                <label for="total_investment" class="text-sm font-medium text-gray-700 dark:text-gray-300">سرمایه کل</label>
                                <input id="total_investment" type="number" step="0.01" wire:model.defer="total_investment"
                                    class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-400 transition" />
                                <p class="mt-1 text-xs text-gray-400">مقدار کل دلخواه برای اختصاص به استراتژی</p>
                            </div>

                            {{-- Fee Percent --}}
                            <div class="flex flex-col">
                                <label for="fee_percent" class="text-sm font-medium text-gray-700 dark:text-gray-300">کارمزد معامله (%)</label>
                                <input id="fee_percent" type="number" step="0.01" wire:model.defer="fee_percent"
                                    class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-400 transition" />
                                <p class="mt-1 text-xs text-gray-400">کارمزد هر خرید/فروش را وارد کنید</p>
                            </div>

                            {{-- Strategy Type --}}
                            <div class="flex flex-col">
                                <label for="strategy_type" class="text-sm font-medium text-gray-700 dark:text-gray-300">نوع استراتژی</label>
                                <select id="strategy_type" wire:model.defer="strategy_type"
                                    class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-400 transition">
                                    <option value="long">لانگ</option>
                                    <option value="short">شورت</option>
                                    <option value="both">دوطرفه</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-400">انتخاب کنید استراتژی در دو طرف فعال باشد یا خیر</p>
                            </div>
                        </div>

                        {{-- Advanced Options --}}
                        <div class="mt-4 rounded-lg border border-dashed border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-900">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-medium text-gray-700 dark:text-gray-300">تنظیمات پیشرفته</div>
                                <div class="text-xs text-gray-400">اختیاری</div>
                            </div>

                            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">نرخ برداشت سود هدف (%)</label>
                                    <input type="number" step="0.01" wire:model.defer="take_profit_percent"
                                        class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 transition" />
                                </div>

                                <div>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">حد ضرر کلی (%)</label>
                                    <input type="number" step="0.01" wire:model.defer="stop_loss_percent"
                                        class="mt-2 block w-full rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 transition" />
                                </div>
                            </div>

                            <p class="mt-3 text-xs text-gray-400">این تنظیمات برای شبیه‌سازی دقیق‌تر در شرایط بازار استفاده می‌شوند.</p>
                        </div>

                        {{-- Submit area with loading indicator --}}
                        <div class="flex items-center justify-between mt-4">
                            <div class="flex items-center gap-3">
                                <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow hover:from-blue-700 hover:to-indigo-700 transition">
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 10h4l3 8 4-16 3 8h4" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    محاسبهٔ کامل
                                </button>

                                <button type="button" wire:click="exportCsv" wire:loading.attr="disabled" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-700 text-sm text-gray-700 dark:text-gray-100 hover:shadow-sm transition">
                                    خروجی CSV
                                </button>

                                <div wire:loading.delay class="inline-flex items-center gap-2 text-sm text-gray-500">
                                    <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 12a9 9 0 11-18 0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    در حال محاسبه...
                                </div>
                            </div>

                            <div class="text-sm text-gray-500">
                                <div>حداقل مبلغ هر سفارش: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $minOrderAmount }}</span></div>
                                <div class="mt-0.5">خطای سیستم: <span class="text-red-500">{{ $errorMessage }}</span></div>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Results & Charts Card --}}
                @if($hasResults)
                    <div class="mt-6 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">نتایج محاسبه</h3>
                            <div class="text-sm text-gray-500">تعداد ردیف: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $rowsCount }}</span></div>
                        </div>

                        {{-- Responsive table --}}
                        <div class="overflow-x-auto rounded-lg">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300">#</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300">قیمت خرید</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300">قیمت فروش</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300">حجم</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300">سود هر گرید</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($results as $i => $row)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition" wire:key="grid-row-{{ $i }}">
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white text-right">{{ $i + 1 }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white text-right">{{ $fmtNum($dg($row, 'buy')) }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white text-right">{{ $fmtNum($dg($row, 'sell')) }}</td>
                                            <td class="px-4 py-3 text-sm text-gray-900 dark:text-white text-right">{{ $fmtNum($dg($row, 'size'), 8) }}</td>
                                            <td class="px-4 py-3 text-sm text-green-600 font-medium text-right">{{ $fmtNum($dg($row, 'profit'), 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Summary row --}}
                        <div class="mt-4 flex items-center justify-between">
                            <div class="text-sm text-gray-500">کل سرمایه مصرف‌شده: <span class="font-medium text-gray-700 dark:text-gray-200">{{ $fmtNum(data_get($summary, 'used')) }}</span></div>
                            <div class="text-sm text-gray-500">سود تخمینی: <span class="font-medium text-green-600">{{ $fmtNum(data_get($summary, 'estimated_profit')) }}</span></div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right: Sidebar Cards --}}
            <aside class="space-y-4">
                {{-- Quick Stats --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow p-4">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">آمار سریع</h4>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-900">
                            <div class="text-xs text-gray-500">وضعیت</div>
                            <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $status }}</div>
                        </div>
                        <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-900">
                            <div class="text-xs text-gray-500">میانگین فاصله</div>
                            <div class="mt-1 font-semibold text-gray-900 dark:text-white">{{ data_get($stats, 'avg_gap', '-') }}</div>
                        </div>
                    </div>
                </div>

                {{-- Tips --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow p-4">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">نکات کلیدی</h4>
                    <ul class="mt-3 space-y-2 text-sm text-gray-500">
                        <li>۱. همیشه کارمزد و اسلیپیج را در محاسبات لحاظ کنید.</li>
                        <li>۲. تعداد گرید زیاد باعث تقسیم سرمایه می‌شود؛ حجم هر سفارش را چک کنید.</li>
                        <li>۳. برای بازارهای ناپایدار، تست استرس را اجرا کنید.</li>
                    </ul>
                </div>

                {{-- Recent Activity --}}
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow p-4">
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">فعالیت اخیر</h4>
                    <div class="mt-3 text-xs text-gray-500 space-y-2">
                        @forelse($recentActivity as $act)
                            <div class="flex items-start gap-2">
                                <div class="w-2.5 h-2.5 rounded-full bg-green-400 mt-1"></div>
                                <div class="leading-tight">
                                    <div class="text-gray-700 dark:text-gray-200 text-sm">{{ data_get($act, 'title', '-') }}</div>
                                    <div class="text-gray-400">{{ data_get($act, 'time', '-') }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="text-gray-400">هیچ فعالیتی یافت نشد.</div>
                        @endforelse
                    </div>
                </div>
            </aside>
        </section>

        {{-- ================= Footer Actions ================= --}}
        <footer class="flex items-center justify-between">
            <div class="text-xs text-gray-500">
                طراحی و پیاده‌سازی مدرن — سازگار با قالب سایت — Filament + Tailwind
            </div>

            <div class="flex items-center gap-3">
                <button type="button" wire:click="openHelp" class="text-sm px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-700 hover:shadow-sm transition">راهنما</button>
                <button type="button" wire:click="savePreset" class="text-sm px-3 py-2 rounded-lg bg-gradient-to-r from-amber-500 to-orange-500 text-white shadow hover:from-amber-600 transition">ذخیرهٔ تنظیمات</button>
            </div>
        </footer>
    </div>
</x-filament-panels::page>
