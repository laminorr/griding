<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Create/Edit Form --}}
        @if($isCreating)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <x-heroicon-o-pencil-square class="w-6 h-6" />
                    {{ $isEditing ? 'ویرایش یادداشت' : 'یادداشت جدید' }}
                </h2>
            </div>
            
            <div class="p-6">
                {{ $this->form }}
            </div>
        </div>
        @endif

        {{-- Search and Filter Bar --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-4">
                <div class="flex flex-col md:flex-row gap-4">
                    {{-- Search --}}
                    <div class="flex-1">
                        <div class="relative">
                            <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                            <input 
                                type="text" 
                                wire:model.live="searchQuery"
                                placeholder="جستجو در یادداشت‌ها..."
                                class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                            />
                        </div>
                    </div>
                    
                    {{-- Category Filter --}}
                    <div class="md:w-48">
                        <select 
                            wire:model.live="filterCategory"
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        >
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
                    </div>
                    
                    {{-- Sort By --}}
                    <div class="md:w-40">
                        <select 
                            wire:model.live="sortBy"
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                        >
                            <option value="newest">جدیدترین</option>
                            <option value="oldest">قدیمی‌ترین</option>
                            <option value="title">عنوان</option>
                            <option value="priority">اولویت</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Notes Grid --}}
        @if(count($this->getFilteredNotes()) > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            @foreach($this->getFilteredNotes() as $note)
            <div 
                class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-xl transition-all duration-300 hover:-translate-y-1 relative group"
                style="border-top: 4px solid {{ $note['color'] }}"
            >
                {{-- Pin Indicator --}}
                @if($note['pinned'] ?? false)
                <div class="absolute top-2 right-2 z-10">
                    <x-heroicon-s-bookmark class="w-5 h-5 text-yellow-500" />
                </div>
                @endif

                {{-- Note Header --}}
                <div class="p-4 pb-2">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white line-clamp-2 flex-1">
                            {{ $note['title'] }}
                        </h3>
                        
                        {{-- Quick Actions --}}
                        <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-200 flex gap-1">
                            <button 
                                wire:click="togglePinNote('{{ $note['id'] }}')"
                                class="p-1 rounded text-gray-400 hover:text-yellow-500 hover:bg-yellow-50 dark:hover:bg-yellow-900/20"
                                title="{{ ($note['pinned'] ?? false) ? 'برداشتن پین' : 'پین کردن' }}"
                            >
                                <x-heroicon-o-bookmark class="w-4 h-4" />
                            </button>
                            
                            <button 
                                wire:click="editNote('{{ $note['id'] }}')"
                                class="p-1 rounded text-gray-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20"
                                title="ویرایش"
                            >
                                <x-heroicon-o-pencil class="w-4 h-4" />
                            </button>
                            
                            <button 
                                wire:click="duplicateNote('{{ $note['id'] }}')"
                                class="p-1 rounded text-gray-400 hover:text-green-500 hover:bg-green-50 dark:hover:bg-green-900/20"
                                title="کپی"
                            >
                                <x-heroicon-o-document-duplicate class="w-4 h-4" />
                            </button>
                            
                            <button 
                                wire:click="deleteNote('{{ $note['id'] }}')"
                                class="p-1 rounded text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                                title="حذف"
                                onclick="confirm('آیا مطمئن هستید؟') || event.stopImmediatePropagation()"
                            >
                                <x-heroicon-o-trash class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                    
                    {{-- Category and Priority --}}
                    <div class="flex items-center gap-2 mb-3">
                        <span class="text-sm">
                            {{ $this->getCategoryIcon($note['category']) }}
                        </span>
                        
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $this->getPriorityColor($note['priority']) }}">
                            {{ ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'بالا', 'urgent' => 'فوری'][$note['priority']] }}
                        </span>
                        
                        @if($note['reminder_at'])
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300">
                            <x-heroicon-o-bell class="w-3 h-3 mr-1" />
                            یادآوری
                        </span>
                        @endif
                    </div>
                </div>

                {{-- Note Content --}}
                <div class="px-4 pb-4">
                    <div class="text-gray-700 dark:text-gray-300 text-sm line-clamp-4 mb-4 whitespace-pre-wrap">
                        {{ $note['content'] }}
                    </div>
                    
                    {{-- Note Footer --}}
                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 pt-3 border-t border-gray-100 dark:border-gray-700">
                        <span>
                            {{ \Carbon\Carbon::parse($note['created_at'])->diffForHumans() }}
                        </span>
                        
                        @if($note['updated_at'] !== $note['created_at'])
                        <span>
                            ویرایش شده
                        </span>
                        @endif
                    </div>
                    
                    {{-- Reminder Time --}}
                    @if($note['reminder_at'])
                    <div class="mt-2 text-xs text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 rounded px-2 py-1">
                        <x-heroicon-o-clock class="w-3 h-3 inline mr-1" />
                        {{ \Carbon\Carbon::parse($note['reminder_at'])->format('Y/m/d H:i') }}
                    </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @else
        {{-- Empty State --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-12 text-center">
                <div class="mx-auto w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                    <x-heroicon-o-chat-bubble-left-right class="w-12 h-12 text-gray-400" />
                </div>
                
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">
                    {{ empty($this->searchQuery) && $this->filterCategory === 'all' ? 'هنوز یادداشتی ندارید' : 'یادداشتی یافت نشد' }}
                </h3>
                
                <p class="text-gray-600 dark:text-gray-400 mb-6">
                    {{ empty($this->searchQuery) && $this->filterCategory === 'all' 
                        ? 'اولین یادداشت خود را ایجاد کنید و ایده‌هایتان را ثبت کنید' 
                        : 'تنظیمات جستجو یا فیلتر را تغییر دهید' 
                    }}
                </p>
                
                @if(empty($this->searchQuery) && $this->filterCategory === 'all')
                <button 
                    wire:click="startCreating"
                    class="inline-flex items-center gap-2 px-6 py-3 bg-emerald-600 text-white font-semibold rounded-lg hover:bg-emerald-700 transition-colors"
                >
                    <x-heroicon-o-plus class="w-5 h-5" />
                    یادداشت جدید
                </button>
                @endif
            </div>
        </div>
        @endif

        {{-- Statistics --}}
        @if(count($this->notes) > 0)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="bg-gradient-to-r from-purple-500 to-pink-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <x-heroicon-o-chart-pie class="w-6 h-6" />
                    آمار یادداشت‌ها
                </h2>
            </div>
            
            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-blue-600 mb-1">{{ count($this->notes) }}</div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">کل یادداشت‌ها</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-3xl font-bold text-yellow-600 mb-1">
                            {{ collect($this->notes)->where('pinned', true)->count() }}
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">پین شده</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-3xl font-bold text-green-600 mb-1">
                            {{ collect($this->notes)->whereNotNull('reminder_at')->count() }}
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">یادآوری دارند</div>
                    </div>
                    
                    <div class="text-center">
                        <div class="text-3xl font-bold text-red-600 mb-1">
                            {{ collect($this->notes)->where('priority', 'high')->count() + collect($this->notes)->where('priority', 'urgent')->count() }}
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">اولویت بالا</div>
                    </div>
                </div>
                
                {{-- Category Distribution --}}
                <div class="mt-8">
                    <h4 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-4">توزیع دسته‌بندی</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-3">
                        @php
                            $categories = collect($this->notes)->groupBy('category');
                            $categoryLabels = [
                                'general' => ['📝', 'عمومی'],
                                'trading' => ['📈', 'معاملات'],
                                'strategy' => ['🎯', 'استراتژی'],
                                'analysis' => ['📊', 'تحلیل'],
                                'ideas' => ['💡', 'ایده‌ها'],
                                'reminders' => ['⏰', 'یادآوری'],
                                'bugs' => ['🐛', 'مشکلات'],
                                'improvements' => ['⚡', 'بهبودها']
                            ];
                        @endphp
                        
                        @foreach($categoryLabels as $key => $label)
                            @php $count = $categories->get($key, collect())->count(); @endphp
                            @if($count > 0)
                            <div class="text-center p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="text-2xl mb-1">{{ $label[0] }}</div>
                                <div class="text-sm font-bold text-gray-900 dark:text-white">{{ $count }}</div>
                                <div class="text-xs text-gray-600 dark:text-gray-400">{{ $label[1] }}</div>
                            </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    <style>
        /* Note card animations */
        .note-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .note-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        /* Line clamp utility */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .line-clamp-4 {
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Custom scrollbar for note content */
        .note-content::-webkit-scrollbar {
            width: 4px;
        }
        
        .note-content::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 2px;
        }
        
        .note-content::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 2px;
        }
        
        /* Masonry layout for notes */
        .notes-masonry {
            column-count: 1;
            column-gap: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .notes-masonry {
                column-count: 2;
            }
        }
        
        @media (min-width: 1024px) {
            .notes-masonry {
                column-count: 3;
            }
        }
        
        @media (min-width: 1280px) {
            .notes-masonry {
                column-count: 4;
            }
        }
        
        .notes-masonry .note-item {
            break-inside: avoid;
            margin-bottom: 1.5rem;
        }
        
        /* Priority pulse animation */
        .priority-urgent {
            animation: pulse-urgent 2s infinite;
        }
        
        @keyframes pulse-urgent {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(147, 51, 234, 0.4);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(147, 51, 234, 0);
            }
        }
        
        /* Search highlight */
        .search-highlight {
            background-color: rgba(16, 185, 129, 0.2);
            padding: 0 2px;
            border-radius: 2px;
        }
        
        /* Reminder notification */
        .reminder-active {
            border-left: 4px solid #8b5cf6;
            background: linear-gradient(to right, rgba(139, 92, 246, 0.05), transparent);
        }
        
        /* Quick action buttons */
        .quick-action {
            opacity: 0;
            transform: translateY(2px);
            transition: all 0.2s ease;
        }
        
        .group:hover .quick-action {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Note color indicator */
        .color-indicator {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 12px 12px 0 0;
        }
        
        /* Drag and drop placeholder */
        .drag-placeholder {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            background: rgba(16, 185, 129, 0.05);
            min-height: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-weight: 500;
        }
        
        /* Loading skeleton */
        .skeleton {
            animation: skeleton-loading 1s linear infinite alternate;
        }
        
        @keyframes skeleton-loading {
            0% {
                background-color: hsl(200, 20%, 80%);
            }
            100% {
                background-color: hsl(200, 20%, 95%);
            }
        }
    </style>

    <script>
        // Auto-save functionality
        let autoSaveTimer;
        
        document.addEventListener('livewire:update', function() {
            // Clear existing timer
            if (autoSaveTimer) {
                clearTimeout(autoSaveTimer);
            }
            
            // Set new auto-save timer
            if (@this.isCreating) {
                autoSaveTimer = setTimeout(() => {
                    // Auto-save draft functionality could be added here
                }, 10000); // 10 seconds
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N for new note
            if ((e.ctrlKey || e.metaKey) && e.key === 'n' && !@this.isCreating) {
                e.preventDefault();
                @this.startCreating();
            }
            
            // Escape to cancel
            if (e.key === 'Escape' && @this.isCreating) {
                @this.cancelEditing();
            }
            
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's' && @this.isCreating) {
                e.preventDefault();
                @this.saveNote();
            }
            
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[wire\\:model\\.live="searchQuery"]')?.focus();
            }
        });
        
        // Search highlighting
        document.addEventListener('livewire:update', function() {
            const searchQuery = @this.searchQuery?.toLowerCase();
            if (searchQuery && searchQuery.length > 2) {
                document.querySelectorAll('.note-content').forEach(element => {
                    const text = element.textContent;
                    const regex = new RegExp(`(${searchQuery})`, 'gi');
                    element.innerHTML = text.replace(regex, '<span class="search-highlight">$1</span>');
                });
            }
        });
        
        // Reminder notifications (if implemented)
        function checkReminders() {
            const now = new Date();
            @this.notes.forEach(note => {
                if (note.reminder_at) {
                    const reminderTime = new Date(note.reminder_at);
                    const diff = reminderTime - now;
                    
                    // Notify 5 minutes before
                    if (diff > 0 && diff < 300000) {
                        // Show browser notification
                        if (Notification.permission === 'granted') {
                            new Notification(`یادآوری: ${note.title}`, {
                                body: note.content.substring(0, 100) + '...',
                                icon: '/favicon.ico'
                            });
                        }
                    }
                }
            });
        }
        
        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
        
        // Check reminders every minute
        setInterval(checkReminders, 60000);
        
        // Note card hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const noteCards = document.querySelectorAll('.note-card');
            noteCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-4px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
        });
        
        // Character count for textarea
        document.addEventListener('input', function(e) {
            if (e.target.tagName === 'TEXTAREA') {
                const maxLength = 1000;
                const currentLength = e.target.value.length;
                
                let counter = e.target.parentNode.querySelector('.char-counter');
                if (!counter) {
                    counter = document.createElement('div');
                    counter.className = 'char-counter text-xs text-gray-500 text-right mt-1';
                    e.target.parentNode.appendChild(counter);
                }
                
                counter.textContent = `${currentLength}/${maxLength}`;
                counter.style.color = currentLength > maxLength ? '#ef4444' : '#6b7280';
            }
        });
        
        // Smooth scroll to new note
        document.addEventListener('livewire:update', function() {
            if (@this.isCreating && !@this.isEditing) {
                setTimeout(() => {
                    document.querySelector('form')?.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'start' 
                    });
                }, 100);
            }
        });
    </script>
</x-filament-panels::page>