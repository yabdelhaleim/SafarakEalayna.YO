<?php

namespace App\Filament\Admin\Resources\BusInventories;

use App\Enums\BusInventoryPaymentType;
use App\Filament\Admin\Concerns\BelongsToBusModuleNavigation;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusInventoryService;
use BackedEnum;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusInventoryResource extends Resource
{
    use BelongsToBusModuleNavigation;

    protected static ?string $model = BusInventory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'الرحلات وأسعار التذاكر';
    protected static ?string $pluralLabel = 'الرحلات وأسعار التذاكر';
    protected static ?string $modelLabel = 'رحلة باص';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'route';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['company', 'account']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('busInventoryTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('trip')
                            ->label('الرحلة والسعة')
                            ->icon(Heroicon::OutlinedMap)
                            ->schema([
                                Section::make('تعريف الرحلة')
                                    ->description('المسار والتاريخ كما تظهر في Vue ووصل الطباعة.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Select::make('company_id', 'شركة الباص')
                                                    ->relationship('company', 'name')
                                                    ->searchable()
                                                    ->preload()
                                                    ->required(),
                                                TextInput::make('route', 'المسار')
                                                    ->placeholder('مثال: القاهرة - الإسكندرية')
                                                    ->required()
                                                    ->maxLength(255)
                                                    ->columnSpanFull(),
                                                DatePicker::make('travel_date', 'تاريخ السفر')
                                                    ->required()
                                                    ->native(false),
                                                TimePicker::make('departure_time', 'وقت المغادرة')
                                                    ->seconds(false)
                                                    ->required(),
                                                TextInput::make('total_tickets', 'إجمالي المقاعد')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(1)
                                                    ->required(),
                                                TextInput::make('available_tickets', 'المقاعد المتاحة')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->helperText('عند الإنشاء يُضبط تلقائياً = الإجمالي إذا تُرك فارغاً.')
                                                    ->default(1),
                                                TextInput::make('cost_per_ticket', 'تكلفة التذكرة للمكتب')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->step(0.01)
                                                    ->required(),
                                                TextInput::make('selling_price', 'سعر بيع التذكرة للعميل')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->step(0.01)
                                                    ->required(),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('finance')
                            ->label('المالية والتحصيل')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make('نوع سداد تكلفة الرحلة للشركة')
                                    ->description('آجل: المديونية على المكتب حتى السداد. نقدي: يجب اختيار حساب الخزينة لتسجيل المصروف (يُفضّل التأكد من القيود من التطبيق عند أول دفعة).')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Select::make('payment_type', 'نوع الدفع')
                                                    ->options(BusInventoryPaymentType::class)
                                                    ->required()
                                                    ->native(false),
                                                Select::make('account_id', 'حساب التحصيل / الخزينة')
                                                    ->relationship('account', 'name', fn ($query) => $query->where('is_active', true))
                                                    ->searchable()
                                                    ->preload()
                                                    ->helperText('مطلوب منطقياً عند نوع «نقدي» لتوجيه المصروف.'),
                                                Textarea::make('notes', 'ملاحظات')
                                                    ->rows(3)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                                Section::make('أرصدة محسوبة (للعرض)')
                                    ->description('تُحدَّث آلياً عند الحفظ حسب المقاعد والتكلفة.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('total_cost')
                                                    ->label('التكلفة الإجمالية')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                TextInput::make('amount_paid')
                                                    ->label('المدفوع للشركة')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                TextInput::make('remaining_debt')
                                                    ->label('متبقي للشركة')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                            ]),
                                    ])
                                    ->collapsed()
                                    ->visible(fn ($livewire) => $livewire instanceof \Filament\Resources\Pages\EditRecord),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('route')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('company.name', 'الشركة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('route', 'المسار')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('travel_date', 'تاريخ السفر')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('departure_time', 'وقت المغادرة')
                    ->time('H:i'),

                TextColumn::make('total_tickets', 'إجمالي')
                    ->numeric(),

                TextColumn::make('available_tickets', 'متاح')
                    ->numeric()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),

                TextColumn::make('sold_tickets', 'المباع')
                    ->getStateUsing(fn ($record) => $record->total_tickets - $record->available_tickets)
                    ->numeric(),

                TextColumn::make('selling_price', 'سعر البيع')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('profit_per_ticket', 'ربح/تذكرة')
                    ->getStateUsing(fn ($record) => $record->selling_price - $record->cost_per_ticket)
                    ->money('EGP')
                    ->color('success'),

                BadgeColumn::make('payment_type', 'نوع الدفع')
                    ->colors([
                        'cash' => 'success',
                        'deferred' => 'warning',
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'cash' => 'نقدي',
                        'deferred' => 'آجل',
                        default => $state,
                    }),

                BadgeColumn::make('is_fully_paid', 'سداد التكلفة')
                    ->getStateUsing(fn ($record) => $record->remaining_debt <= 0)
                    ->colors([
                        true => 'success',
                        false => 'warning',
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'مسدد' : 'له مديونية'),

                TextColumn::make('remaining_debt', 'المتبقي')
                    ->money('EGP')
                    ->color('danger'),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('company_id', 'الشركة')
                    ->relationship('company', 'name')
                    ->searchable(),

                SelectFilter::make('payment_type', 'نوع الدفع')
                    ->options(BusInventoryPaymentType::class),

                SelectFilter::make('has_available', 'هل فيه مقاعد؟')
                    ->options([
                        '1' => 'فيه مقاعد متاحة',
                        '0' => 'مافيش مقاعد',
                    ])
                    ->query(function ($query, $value) {
                        if ($value === '1') {
                            return $query->hasAvailableTickets();
                        }

                        if ($value === '0') {
                            return $query->where('available_tickets', '<=', 0);
                        }

                        return $query;
                    }),

                SelectFilter::make('with_debt', 'عندها مديونية')
                    ->options([
                        '1' => 'عندها مديونية',
                        '0' => 'مسدد بالكامل',
                    ])
                    ->query(function ($query, $value) {
                        if ($value === '1') {
                            return $query->withDebt();
                        }

                        if ($value === '0') {
                            return $query->where('remaining_debt', '<=', 0);
                        }

                        return $query;
                    }),
            ])
            ->defaultSort('travel_date', 'desc')
            ->recordActions([
                EditAction::make()
                    ->using(function (array $data, $livewire, Model $record): void {
                        if (! $record instanceof BusInventory) {
                            throw new \InvalidArgumentException('Expected bus inventory record.');
                        }

                        app(BusInventoryService::class)->updateInventory($record, [
                            'route' => $data['route'] ?? null,
                            'travel_date' => $data['travel_date'] ?? null,
                            'departure_time' => $data['departure_time'] ?? null,
                            'selling_price' => $data['selling_price'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ]);
                    }),
                Action::make('payDebt')
                    ->label('سداد مديونية')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (BusInventory $record): bool => $record->payment_type === BusInventoryPaymentType::Deferred->value && (float) $record->remaining_debt > 0)
                    ->modalHeading(fn (BusInventory $record): string => 'سداد مديونية الشركة — '.$record->company?->name)
                    ->modalDescription(fn (BusInventory $record): string => 'المتبقي على الرحلة: '.number_format((float) $record->remaining_debt, 2).' ج.م')
                    ->form([
                        TextInput::make('amount')
                            ->label('المبلغ المسدد')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (BusInventory $record) => (float) $record->remaining_debt)
                            ->default(fn (BusInventory $record) => (float) $record->remaining_debt)
                            ->prefix('ج.م'),
                        Select::make('account_id')
                            ->label('حساب السداد (الخزينة)')
                            ->relationship('account', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (BusInventory $record, array $data): void {
                        try {
                            app(BusInventoryService::class)->payInventoryDebt($record, [
                                'amount' => (float) $data['amount'],
                                'account_id' => (int) $data['account_id'],
                                'notes' => $data['notes'] ?? null,
                            ]);

                            Notification::make()
                                ->title('تم تسجيل السداد بنجاح')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('فشل تسجيل السداد')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageBusInventories::route('/'),
        ];
    }
}
