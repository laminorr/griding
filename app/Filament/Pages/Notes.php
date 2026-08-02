<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Notes extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    
    protected static ?string $navigationLabel = 'گفتمان';
    
    protected static ?string $title = 'گفتمان و یادداشت‌ها';
    
    protected static ?string $navigationGroup = 'ابزارها';
    
    protected static ?int $navigationSort = 3;
    
    protected static string $view = 'filament.pages.notes';
    
    // Form Data
    public ?array $data = [];
    
    // Notes Data
    public $notes = [];
    public $selectedNote = null;
    public $searchQuery = '';
    public $filterCategory = 'all';
    public $sortBy = 'newest';
    
    // UI States
    public $isCreating = false;
    public $isEditing = false;
    public $editingNoteId = null;

    public function mount(): void
    {
        $this->loadNotes();
        $this->form->fill([
            'category' => 'general',
            'color' => '#10b981',
            'priority' => 'medium'
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('title')
                    ->label('عنوان یادداشت')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('عنوان کوتاه و توصیفی...')
                    ->autocomplete(false),
                
                Select::make('category')
                    ->label('دسته‌بندی')
                    ->options([
                        'general' => '📝 عمومی',
                        'trading' => '📈 معاملات',
                        'strategy' => '🎯 استراتژی',
                        'analysis' => '📊 تحلیل',
                        'ideas' => '💡 ایده‌ها',
                        'reminders' => '⏰ یادآوری‌ها',
                        'bugs' => '🐛 مشکلات',
                        'improvements' => '⚡ بهبودها'
                    ])
                    ->default('general')
                    ->native(false),
                
                Select::make('priority')
                    ->label('اولویت')
                    ->options([
                        'low' => '🟢 کم',
                        'medium' => '🟡 متوسط',
                        'high' => '🔴 بالا',
                        'urgent' => '🚨 فوری'
                    ])
                    ->default('medium')
                    ->native(false),
                
                ColorPicker::make('color')
                    ->label('رنگ')
                    ->default('#10b981'),
                
                Textarea::make('content')
                    ->label('محتوای یادداشت')
                    ->required()
                    ->rows(6)
                    ->placeholder('یادداشت خود را اینجا بنویسید...')
                    ->autosize(),
                
                DateTimePicker::make('reminder_at')
                    ->label('زمان یادآوری')
                    ->nullable()
                    ->native(false)
                    ->displayFormat('Y/m/d H:i')
                    ->helperText('اختیاری - برای تنظیم یادآوری')
            ])
            ->statePath('data');
    }

    protected function getActions(): array
    {
        return [
            Action::make('create')
                ->label($this->isCreating ? 'ذخیره یادداشت' : 'یادداشت جدید')
                ->icon($this->isCreating ? 'heroicon-o-check' : 'heroicon-o-plus')
                ->color($this->isCreating ? 'success' : 'primary')
                ->size('sm')
                ->action($this->isCreating ? 'saveNote' : 'startCreating')
                ->keyBindings(['command+n', 'ctrl+n']),
                
            Action::make('cancel')
                ->label('انصراف')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->action('cancelEditing')
                ->visible(fn () => $this->isCreating || $this->isEditing),
                
            Action::make('export')
                ->label('صادرات')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action('exportNotes'),
                
            Action::make('clear_all')
                ->label('پاک کردن همه')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('پاک کردن تمام یادداشت‌ها')
                ->modalDescription('آیا مطمئن هستید؟ این عمل قابل بازگشت نیست.')
                ->action('clearAllNotes')
                ->visible(fn () => count($this->notes) > 0),
        ];
    }

    public function startCreating(): void
    {
        $this->isCreating = true;
        $this->isEditing = false;
        $this->editingNoteId = null;
        $this->form->fill([
            'category' => 'general',
            'color' => '#10b981',
            'priority' => 'medium'
        ]);
    }

    public function saveNote(): void
    {
        try {
            $data = $this->form->getState();
            
            if ($this->isEditing && $this->editingNoteId) {
                // Update existing note
                $this->updateNote($this->editingNoteId, $data);
            } else {
                // Create new note
                $this->createNote($data);
            }
            
            $this->isCreating = false;
            $this->isEditing = false;
            $this->editingNoteId = null;
            $this->form->fill([
                'category' => 'general',
                'color' => '#10b981',
                'priority' => 'medium'
            ]);
            
        } catch (\Exception $e) {
            Notification::make()
                ->title('خطا در ذخیره یادداشت')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function editNote($noteId): void
    {
        $note = collect($this->notes)->firstWhere('id', $noteId);
        if ($note) {
            $this->isEditing = true;
            $this->isCreating = true; // Use same form
            $this->editingNoteId = $noteId;
            $this->form->fill($note);
        }
    }

    public function deleteNote($noteId): void
    {
        $this->notes = collect($this->notes)->reject(fn($note) => $note['id'] === $noteId)->values()->toArray();
        $this->saveNotesToStorage();
        
        Notification::make()
            ->title('🗑️ یادداشت حذف شد')
            ->success()
            ->send();
    }

    public function togglePinNote($noteId): void
    {
        $this->notes = collect($this->notes)->map(function($note) use ($noteId) {
            if ($note['id'] === $noteId) {
                $note['pinned'] = !($note['pinned'] ?? false);
                $note['updated_at'] = now()->toISOString();
            }
            return $note;
        })->toArray();
        
        $this->saveNotesToStorage();
        
        $isPinned = collect($this->notes)->firstWhere('id', $noteId)['pinned'] ?? false;
        
        Notification::make()
            ->title($isPinned ? '📌 یادداشت پین شد' : '📌 پین برداشته شد')
            ->info()
            ->send();
    }

    public function duplicateNote($noteId): void
    {
        $note = collect($this->notes)->firstWhere('id', $noteId);
        if ($note) {
            $newNote = $note;
            $newNote['id'] = Str::uuid()->toString();
            $newNote['title'] = $note['title'] . ' (کپی)';
            $newNote['created_at'] = now()->toISOString();
            $newNote['updated_at'] = now()->toISOString();
            $newNote['pinned'] = false;
            
            array_unshift($this->notes, $newNote);
            $this->saveNotesToStorage();
            
            Notification::make()
                ->title('📄 یادداشت کپی شد')
                ->success()
                ->send();
        }
    }

    public function cancelEditing(): void
    {
        $this->isCreating = false;
        $this->isEditing = false;
        $this->editingNoteId = null;
        $this->form->fill([
            'category' => 'general',
            'color' => '#10b981',
            'priority' => 'medium'
        ]);
    }

    public function exportNotes(): void
    {
        try {
            $exportData = [
                'exported_at' => now()->format('Y-m-d H:i:s'),
                'total_notes' => count($this->notes),
                'notes' => $this->notes
            ];
            
            $fileName = 'notes_export_' . now()->format('Y_m_d_H_i_s') . '.json';
            $content = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            Storage::disk('public')->put('exports/' . $fileName, $content);
            
            Notification::make()
                ->title('📤 صادرات انجام شد')
                ->body("فایل {$fileName} در پوشه exports ذخیره شد")
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Notification::make()
                ->title('خطا در صادرات')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function clearAllNotes(): void
    {
        $this->notes = [];
        $this->saveNotesToStorage();
        
        Notification::make()
            ->title('🗑️ تمام یادداشت‌ها پاک شدند')
            ->warning()
            ->send();
    }

    // Search and Filter
    public function updatedSearchQuery(): void
    {
        // Triggered when search query changes
    }

    public function updatedFilterCategory(): void
    {
        // Triggered when filter changes
    }

    public function updatedSortBy(): void
    {
        // Triggered when sort changes
    }

    // Helper Methods
    private function createNote(array $data): void
    {
        $note = [
            'id' => Str::uuid()->toString(),
            'title' => $data['title'],
            'content' => $data['content'],
            'category' => $data['category'],
            'priority' => $data['priority'],
            'color' => $data['color'],
            'reminder_at' => $data['reminder_at'] ? Carbon::parse($data['reminder_at'])->toISOString() : null,
            'pinned' => false,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString()
        ];
        
        array_unshift($this->notes, $note);
        $this->saveNotesToStorage();
        
        Notification::make()
            ->title('✅ یادداشت ذخیره شد')
            ->body($data['title'])
            ->success()
            ->send();
    }

    private function updateNote(string $noteId, array $data): void
    {
        $this->notes = collect($this->notes)->map(function($note) use ($noteId, $data) {
            if ($note['id'] === $noteId) {
                $note['title'] = $data['title'];
                $note['content'] = $data['content'];
                $note['category'] = $data['category'];
                $note['priority'] = $data['priority'];
                $note['color'] = $data['color'];
                $note['reminder_at'] = $data['reminder_at'] ? Carbon::parse($data['reminder_at'])->toISOString() : null;
                $note['updated_at'] = now()->toISOString();
            }
            return $note;
        })->toArray();
        
        $this->saveNotesToStorage();
        
        Notification::make()
            ->title('✅ یادداشت بروزرسانی شد')
            ->success()
            ->send();
    }

    private function loadNotes(): void
    {
        if (Storage::disk('local')->exists('notes/user_notes.json')) {
            $content = Storage::disk('local')->get('notes/user_notes.json');
            $this->notes = json_decode($content, true) ?? [];
        } else {
            $this->notes = [];
        }
    }

    private function saveNotesToStorage(): void
    {
        Storage::disk('local')->put('notes/user_notes.json', json_encode($this->notes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getFilteredNotes(): array
    {
        $filtered = collect($this->notes);
        
        // Apply search filter
        if (!empty($this->searchQuery)) {
            $filtered = $filtered->filter(function($note) {
                return Str::contains(strtolower($note['title'] . ' ' . $note['content']), strtolower($this->searchQuery));
            });
        }
        
        // Apply category filter
        if ($this->filterCategory !== 'all') {
            $filtered = $filtered->filter(fn($note) => $note['category'] === $this->filterCategory);
        }
        
        // Apply sorting
        $filtered = match($this->sortBy) {
            'newest' => $filtered->sortByDesc('created_at'),
            'oldest' => $filtered->sortBy('created_at'),
            'title' => $filtered->sortBy('title'),
            'priority' => $filtered->sortBy(function($note) {
                return ['low' => 1, 'medium' => 2, 'high' => 3, 'urgent' => 4][$note['priority']] ?? 2;
            }),
            default => $filtered->sortByDesc('created_at')
        };
        
        // Pinned notes first
        return $filtered->sortByDesc('pinned')->values()->toArray();
    }

    public function getCategoryIcon(string $category): string
    {
        return match($category) {
            'trading' => '📈',
            'strategy' => '🎯',
            'analysis' => '📊',
            'ideas' => '💡',
            'reminders' => '⏰',
            'bugs' => '🐛',
            'improvements' => '⚡',
            default => '📝'
        };
    }

    public function getPriorityColor(string $priority): string
    {
        return match($priority) {
            'low' => 'text-green-600 bg-green-100',
            'medium' => 'text-yellow-600 bg-yellow-100',
            'high' => 'text-red-600 bg-red-100',
            'urgent' => 'text-purple-600 bg-purple-100',
            default => 'text-gray-600 bg-gray-100'
        };
    }
}