<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotConfigResource\Pages;
use App\Models\BotConfig;
use App\Services\TradingEngineService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Cache;

class BotConfigResource extends Resource
{
    protected static ?string $model = BotConfig::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    
    protected static ?string $navigationLabel = 'ربات‌های گرید';
    
    protected static ?string $modelLabel = 'ربات گرید';
    
    protected static ?string $pluralModelLabel = 'ربات‌های گرید';
    
    protected static ?string $navigationGroup = 'معاملات';
    
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Basic Info Section
            Section::make('اطلاعات پایه')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')
                            ->label('نام ربات')
                            ->required()
                            ->maxLength(255)
                            ->default(fn () => 'Grid Bot #' . (BotConfig::count() + 1))
                            ->placeholder('نام دلخواه برای ربات...')
                            ->prefixIcon('heroicon-o-identification'),

                        Toggle::make('simulation')
                            ->label('حالت شبیه‌سازی')
                            ->helperText('در حالت شبیه‌سازی، هیچ سفارش واقعی به نوبیتکس ارسال نمی‌شود')
                            ->default(true)
                            ->inline(false)
                            ->required()
                            ->onIcon('heroicon-o-beaker')
                            ->offIcon('heroicon-o-currency-dollar')
                            ->onColor('warning')
                            ->offColor('success'),

                        Toggle::make('is_active')
                            ->label('فعال‌سازی ربات')
                            ->onIcon('heroicon-o-play')
                            ->offIcon('heroicon-o-pause')
                            ->onColor('success')
                            ->offColor('danger')
                            ->helperText('با فعال کردن، ربات شروع به معامله می‌کند'),
                    ]),
                ]),

            // Capital Configuration
            Section::make('💰 تنظیمات سرمایه')
                ->description('مدیریت سرمایه و ریسک')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('total_capital')
                            ->label('سرمایه کل (IRR)')
                            ->numeric()
                            ->required()
                            ->default(100000000)
                            ->minValue(50000000)
                            ->helperText('حداقل 50 میلیون IRR'),
                        
                        TextInput::make('active_capital_percent')
                            ->label('درصد سرمایه فعال')
                            ->numeric()
                            ->required()
                            ->suffix('%')
                            ->default(30)
                            ->minValue(10)
                            ->maxValue(80)
                            ->helperText('توصیه: 30% برای شروع'),
                    ]),
                ]),

            // Grid Configuration
            Section::make('⚙️ تنظیمات گرید')
                ->description('پیکربندی استراتژی معاملاتی')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('grid_spacing')
                            ->label('فاصله بین سطوح')
                            ->numeric()
                            ->suffix('%')
                            ->default(1.5)
                            ->minValue(0.5)
                            ->maxValue(5.0)
                            ->step(0.1)
                            ->helperText('توصیه: 1.5% برای شروع'),
                        
                        Select::make('grid_levels')
                            ->label('تعداد سطوح')
                            ->options([
                                4 => '4 سطح (ساده)',
                                6 => '6 سطح (متوسط)',
                                8 => '8 سطح (استاندارد)',
                                10 => '10 سطح (پیشرفته)',
                                12 => '12 سطح (حرفه‌ای)',
                                16 => '16 سطح (پیچیده)',
                                20 => '20 سطح (ماکسیمم)'
                            ])
                            ->default(10)
                            ->selectablePlaceholder(false),
                        
                        TextInput::make('stop_loss_percent')
                            ->label('حد ضرر')
                            ->numeric()
                            ->suffix('%')
                            ->default(5)
                            ->minValue(1)
                            ->maxValue(20)
                            ->step(0.5)
                            ->helperText('برای مدیریت ریسک'),
                    ]),
                ]),

            // Advanced Settings
            Section::make('تنظیمات پیشرفته')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('center_price')
                            ->label('قیمت مرکزی (IRR)')
                            ->numeric()
                            ->disabled()
                            ->default(fn () => Cache::get('btc_price', 6000000000))
                            ->helperText('تنظیم خودکار بر اساس قیمت فعلی BTC'),
                        
                        TextInput::make('max_drawdown_percent')
                            ->label('حداکثر افت سرمایه')
                            ->numeric()
                            ->suffix('%')
                            ->default(10)
                            ->minValue(5)
                            ->maxValue(50)
                            ->helperText('برای توقف اضطراری'),
                    ]),
                    
                    Textarea::make('notes')
                        ->label('یادداشت‌های شخصی')
                        ->placeholder('توضیحات، یادداشت‌ها یا استراتژی خاص خود را بنویسید...')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // نام ربات با وضعیت
                TextColumn::make('name')
                    ->label('نام ربات')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->formatStateUsing(function ($record) {
                        $status = $record->is_active ? '🟢 فعال' : '⚫ متوقف';
                        $mode = $record->simulation ? '🧪 شبیه‌سازی' : '💰 واقعی';
                        $modeColor = $record->simulation ? 'text-orange-600' : 'text-green-600';

                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-bold text-gray-900 dark:text-white'>{$record->name}</div>
                                <div class='text-sm text-gray-600 dark:text-gray-400 mt-1'>{$status}</div>
                                <div class='text-xs {$modeColor} font-semibold mt-1'>{$mode}</div>
                            </div>
                        ");
                    })
                    ->alignment(Alignment::Center),

                // وضعیت آیکون
                IconColumn::make('is_active')
                    ->label('وضعیت')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-pause-circle')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->alignment(Alignment::Center),

                // سود/زیان
                TextColumn::make('total_profit')
                    ->label('سود/زیان')
                    ->getStateUsing(function ($record) {
                        return $record->completedTrades()
                            ->selectRaw('COALESCE(SUM(profit - COALESCE(fee, 0)), 0) as net_profit')
                            ->value('net_profit') ?? 0;
                    })
                    ->formatStateUsing(function ($state) {
                        $sign = $state >= 0 ? '+' : '';
                        $color = $state >= 0 ? 'text-green-600' : 'text-red-600';
                        
                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-bold {$color}'>
                                    {$sign}" . number_format($state, 0) . " IRR
                                </div>
                            </div>
                        ");
                    })
                    ->sortable()
                    ->alignment(Alignment::Center),

                // معاملات
                TextColumn::make('completed_trades_count')
                    ->label('معاملات')
                    ->counts('completedTrades')
                    ->formatStateUsing(function ($state) {
                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-semibold text-blue-600'>{$state}</div>
                                <div class='text-sm text-gray-500'>معامله</div>
                            </div>
                        ");
                    })
                    ->sortable()
                    ->alignment(Alignment::Center),

                // تنظیمات گرید
                TextColumn::make('grid_config')
                    ->label('تنظیمات گرید')
                    ->formatStateUsing(function ($state, $record) {
                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-semibold'>{$record->grid_levels} سطح</div>
                                <div class='text-sm text-gray-500'>{$record->grid_spacing}% فاصله</div>
                            </div>
                        ");
                    })
                    ->alignment(Alignment::Center),

                // سرمایه فعال
                TextColumn::make('active_capital')
                    ->label('سرمایه فعال')
                    ->getStateUsing(function ($record) {
                        return ($record->total_capital * $record->active_capital_percent) / 100;
                    })
                    ->formatStateUsing(function ($state, $record) {
                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-semibold text-emerald-600'>" . number_format($state, 0) . " IRR</div>
                                <div class='text-sm text-gray-500'>{$record->active_capital_percent}% فعال</div>
                            </div>
                        ");
                    })
                    ->sortable()
                    ->alignment(Alignment::Center),

                // سرمایه کل
                TextColumn::make('total_capital')
                    ->label('سرمایه کل')
                    ->formatStateUsing(function ($state) {
                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-semibold text-blue-600'>" . number_format($state, 0) . " IRR</div>
                            </div>
                        ");
                    })
                    ->sortable()
                    ->alignment(Alignment::Center),

                // نرخ موفقیت
                TextColumn::make('win_rate')
                    ->label('نرخ موفقیت')
                    ->getStateUsing(function ($record) {
                        $totalTrades = $record->completedTrades()->count();
                        if ($totalTrades === 0) {
                            return 0;
                        }
                        
                        $winningTrades = $record->completedTrades()
                            ->where('profit', '>', 0)
                            ->count();
                        
                        return round(($winningTrades / $totalTrades) * 100, 1);
                    })
                    ->formatStateUsing(function ($state) {
                        $color = match(true) {
                            $state >= 70 => 'text-green-600',
                            $state >= 50 => 'text-yellow-600',
                            default => 'text-red-600'
                        };
                        
                        return new HtmlString("
                            <div class='text-center'>
                                <div class='font-bold {$color}'>{$state}%</div>
                                <div class='text-sm text-gray-500'>موفقیت</div>
                            </div>
                        ");
                    })
                    ->sortable()
                    ->alignment(Alignment::Center),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('is_active')
                    ->label('وضعیت')
                    ->options([
                        1 => 'فعال',
                        0 => 'غیرفعال',
                    ])
                    ->placeholder('همه'),

                SelectFilter::make('simulation')
                    ->label('حالت')
                    ->options([
                        1 => '🧪 شبیه‌سازی',
                        0 => '💰 واقعی',
                    ])
                    ->placeholder('همه'),

                Filter::make('profitable')
                    ->label('سودآور')
                    ->query(fn (Builder $query): Builder =>
                        $query->whereHas('completedTrades', function ($q) {
                            $q->selectRaw('SUM(profit - COALESCE(fee, 0)) as net_profit')
                              ->groupBy('bot_config_id')
                              ->havingRaw('SUM(profit - COALESCE(fee, 0)) > 0');
                        })
                    )
                    ->toggle(),

                SelectFilter::make('grid_levels')
                    ->label('تعداد سطوح')
                    ->options([
                        4 => '4 سطح',
                        6 => '6 سطح', 
                        8 => '8 سطح',
                        10 => '10 سطح',
                        12 => '12 سطح',
                        16 => '16 سطح',
                        20 => '20 سطح',
                    ])
                    ->placeholder('همه'),
            ])
            ->actions([
                Action::make('start')
                    ->label('')
                    ->tooltip('شروع')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->size(ActionSize::Small)
                    ->visible(fn ($record) => !$record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('شروع ربات')
                    ->modalDescription(fn ($record) => "آیا می‌خواهید ربات '{$record->name}' را شروع کنید؟")
                    ->modalSubmitActionLabel('شروع')
                    ->action(function ($record) {
                        try {
                            $tradingEngine = app(TradingEngineService::class);
                            $result = $tradingEngine->initializeGrid($record);
                            
                            if ($result['success']) {
                                $record->update(['is_active' => true]);
                                
                                Notification::make()
                                    ->title('✅ ربات شروع شد')
                                    ->body("ربات {$record->name} با موفقیت شروع شد.")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('❌ خطا در شروع')
                                    ->body($result['message'] ?? 'خطای نامشخص')
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('خطا در شروع ربات')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('stop')
                    ->label('')
                    ->tooltip('توقف')
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->size(ActionSize::Small)
                    ->visible(fn ($record) => $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('توقف ربات')
                    ->modalDescription(fn ($record) => "آیا می‌خواهید ربات '{$record->name}' را متوقف کنید؟")
                    ->modalSubmitActionLabel('توقف')
                    ->action(function ($record) {
                        try {
                            // متوقف کردن ربات
                            $record->update(['is_active' => false]);
                            
                            Notification::make()
                                ->title('⏸️ ربات متوقف شد')
                                ->body("ربات {$record->name} متوقف شد.")
                                ->warning()
                                ->send();
                                
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('خطا در توقف ربات')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make()
                    ->label('')
                    ->tooltip('ویرایش')
                    ->icon('heroicon-o-pencil')
                    ->color('gray')
                    ->size(ActionSize::Small),

                DeleteAction::make()
                    ->label('')
                    ->tooltip('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->size(ActionSize::Small)
                    ->requiresConfirmation()
                    ->visible(fn ($record) => !$record->is_active),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('🤖 هیچ رباتی موجود نیست')
            ->emptyStateDescription('برای شروع معامله هوشمند، اولین ربات گرید خود را ایجاد کنید')
            ->emptyStateIcon('heroicon-o-cpu-chip')
            ->emptyStateActions([
                Action::make('create')
                    ->label('ایجاد اولین ربات')
                    ->url(static::getUrl('create'))
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->color('success'),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotConfigs::route('/'),
            'create' => Pages\CreateBotConfig::route('/create'),
            'edit' => Pages\EditBotConfig::route('/{record}/edit'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        $active = static::getModel()::where('is_active', true)->count();
        return $active > 0 ? (string) $active : null;
    }
    
    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
    
    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }
}