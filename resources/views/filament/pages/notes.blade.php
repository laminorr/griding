{{--
    Notes — Phase P4 (part 2): restyled onto the compact terminal system.
    Dark surfaces, thin dividers, small uppercase labels, accent green; the
    per-note colour is kept as a thin start-edge accent (user data). All
    Livewire methods, the form, search/filter/sort and actions are unchanged.
--}}
<x-filament-panels::page>
    <div class="at-stack">

        {{-- Create / edit form --}}
        @if($isCreating)
            <div class="panel-section">
                <div class="panel-section__head">
                    <span class="panel-section__title">{{ $isEditing ? 'ویرایش یادداشت' : 'یادداشت جدید' }}</span>
                </div>
                <div class="panel-section__body">
                    {{ $this->form }}
                </div>
            </div>
        @endif

        {{-- Search / filter / sort --}}
        <div class="panel-section">
            <div class="panel-section__body">
                <div class="at-row" style="flex-wrap: wrap; gap: var(--at-gap-sm);">
                    <input
                        type="text"
                        wire:model.live="searchQuery"
                        placeholder="جستجو در یادداشت‌ها…"
                        class="at-select"
                        style="flex: 1; min-inline-size: 180px;"
                    />
                    <select wire:model.live="filterCategory" class="at-select">
                        <option value="all">همه دسته‌ها</option>
                        <option value="general">📝 عمومی</option>
                        <option value="trading">📈 معاملات</option>
                        <option value="strategy">🎯 استراتژی</option>
                        <option value="analysis">📊 تحلیل</option>
                        <option value="ideas">💡 ایده‌ها</option>
                        <option value="reminders">⏰ یادآوری‌ها</option>
                        <option value="bugs">🐛 مشکلات</option>
                        <option value="improvements">⚡ بهبودها</option>
                    </select>
                    <select wire:model.live="sortBy" class="at-select">
                        <option value="newest">جدیدترین</option>
                        <option value="oldest">قدیمی‌ترین</option>
                        <option value="title">عنوان</option>
                        <option value="priority">اولویت</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Notes grid --}}
        @php
            $priorityTone = ['low' => 'pos', 'medium' => 'warn', 'high' => 'neg', 'urgent' => 'neg'];
            $priorityLabel = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'بالا', 'urgent' => 'فوری'];
        @endphp
        @if(count($this->getFilteredNotes()) > 0)
            <div class="metric-grid" style="grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); align-items: start;">
                @foreach($this->getFilteredNotes() as $note)
                    <div class="panel-section" style="border-inline-start: 3px solid {{ $note['color'] }};">
                        <div class="panel-section__head">
                            <div class="at-row" style="gap: var(--at-gap-xs); min-inline-size: 0;">
                                <span>{{ $this->getCategoryIcon($note['category']) }}</span>
                                <span class="panel-section__title" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $note['title'] }}</span>
                                @if($note['pinned'] ?? false)
                                    <span class="at-t-warn" title="پین شده">📌</span>
                                @endif
                            </div>
                            <div class="at-row" style="gap: 2px;">
                                <button type="button" wire:click="togglePinNote('{{ $note['id'] }}')" class="at-btn" style="padding: 3px 6px;" title="پین">📌</button>
                                <button type="button" wire:click="editNote('{{ $note['id'] }}')" class="at-btn" style="padding: 3px 6px;" title="ویرایش">✏️</button>
                                <button type="button" wire:click="duplicateNote('{{ $note['id'] }}')" class="at-btn" style="padding: 3px 6px;" title="کپی">⧉</button>
                                <button type="button" wire:click="deleteNote('{{ $note['id'] }}')" class="at-btn at-btn--danger" style="padding: 3px 6px;" title="حذف"
                                        onclick="confirm('آیا مطمئن هستید؟') || event.stopImmediatePropagation()">🗑</button>
                            </div>
                        </div>
                        <div class="panel-section__body at-stack-sm">
                            <div class="at-row" style="gap: var(--at-gap-xs); flex-wrap: wrap;">
                                <span class="at-badge {{ $priorityTone[$note['priority']] ?? 'muted' }}">{{ $priorityLabel[$note['priority']] ?? '' }}</span>
                                @if($note['reminder_at'])
                                    <span class="at-badge muted">⏰ یادآوری</span>
                                @endif
                            </div>
                            <div style="font-size: var(--at-fs-body); color: var(--at-text-dim); white-space: pre-wrap; max-height: 96px; overflow: hidden;">{{ $note['content'] }}</div>
                            <div class="at-row at-kv__k" style="justify-content: space-between; border-block-start: 1px solid var(--at-border); padding-block-start: var(--at-gap-xs);">
                                <span>{{ \Carbon\Carbon::parse($note['created_at'])->diffForHumans() }}</span>
                                @if($note['updated_at'] !== $note['created_at'])
                                    <span>ویرایش شده</span>
                                @endif
                            </div>
                            @if($note['reminder_at'])
                                <div class="at-badge muted" style="justify-content: center;">⏰ {{ \Carbon\Carbon::parse($note['reminder_at'])->format('Y/m/d H:i') }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- Empty state --}}
            <div class="panel-section">
                <div class="at-empty">
                    <div class="at-empty__icon">💬</div>
                    <p style="color: var(--at-text); font-size: var(--at-fs-body);">
                        {{ empty($this->searchQuery) && $this->filterCategory === 'all' ? 'هنوز یادداشتی ندارید' : 'یادداشتی یافت نشد' }}
                    </p>
                    <p class="at-kv__k" style="margin-block: var(--at-gap-xs) var(--at-gap-sm);">
                        {{ empty($this->searchQuery) && $this->filterCategory === 'all'
                            ? 'اولین یادداشت خود را ایجاد کنید'
                            : 'تنظیمات جستجو یا فیلتر را تغییر دهید' }}
                    </p>
                    @if(empty($this->searchQuery) && $this->filterCategory === 'all')
                        <button type="button" wire:click="startCreating" class="at-btn at-btn--accent">＋ یادداشت جدید</button>
                    @endif
                </div>
            </div>
        @endif

        {{-- Statistics --}}
        @if(count($this->notes) > 0)
            <div class="panel-section">
                <div class="panel-section__head">
                    <span class="panel-section__title">آمار یادداشت‌ها</span>
                </div>
                <div class="panel-section__body">
                    <div class="metric-grid">
                        <div class="metric-card">
                            <span class="metric-label">کل یادداشت‌ها</span>
                            <span class="metric-value">{{ count($this->notes) }}</span>
                        </div>
                        <div class="metric-card">
                            <span class="metric-label">پین شده</span>
                            <span class="metric-value at-t-warn">{{ collect($this->notes)->where('pinned', true)->count() }}</span>
                        </div>
                        <div class="metric-card">
                            <span class="metric-label">یادآوری دارند</span>
                            <span class="metric-value pos">{{ collect($this->notes)->whereNotNull('reminder_at')->count() }}</span>
                        </div>
                        <div class="metric-card">
                            <span class="metric-label">اولویت بالا</span>
                            <span class="metric-value neg">{{ collect($this->notes)->where('priority', 'high')->count() + collect($this->notes)->where('priority', 'urgent')->count() }}</span>
                        </div>
                    </div>

                    {{-- Category distribution --}}
                    <div style="margin-block-start: var(--at-gap-md);">
                        <span class="metric-label">توزیع دسته‌بندی</span>
                        <div class="at-row" style="flex-wrap: wrap; margin-block-start: var(--at-gap-xs);">
                            @php
                                $categories = collect($this->notes)->groupBy('category');
                                $categoryLabels = [
                                    'general' => ['📝', 'عمومی'], 'trading' => ['📈', 'معاملات'],
                                    'strategy' => ['🎯', 'استراتژی'], 'analysis' => ['📊', 'تحلیل'],
                                    'ideas' => ['💡', 'ایده‌ها'], 'reminders' => ['⏰', 'یادآوری'],
                                    'bugs' => ['🐛', 'مشکلات'], 'improvements' => ['⚡', 'بهبودها'],
                                ];
                            @endphp
                            @foreach($categoryLabels as $key => $label)
                                @php $count = $categories->get($key, collect())->count(); @endphp
                                @if($count > 0)
                                    <span class="at-badge muted">{{ $label[0] }} {{ $label[1] }} · {{ $count }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'n' && !@this.isCreating) {
                e.preventDefault();
                @this.startCreating();
            }
            if (e.key === 'Escape' && @this.isCreating) {
                @this.cancelEditing();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 's' && @this.isCreating) {
                e.preventDefault();
                @this.saveNote();
            }
        });
    </script>
</x-filament-panels::page>
