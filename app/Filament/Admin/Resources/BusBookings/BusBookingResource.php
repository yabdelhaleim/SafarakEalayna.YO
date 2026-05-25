<?php

namespace App\Filament\Admin\Resources\BusBookings;

use App\Enums\BusBookingStatus;
use App\Enums\BusPaymentStatus;
use App\Filament\Admin\Concerns\BelongsToBusModuleNavigation;
use App\Models\Bus\BusBooking;
use App\Models\Employee;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BusBookingResource extends Resource
{
    use BelongsToBusModuleNavigation;

    protected static ?string $model = BusBooking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'حجوزات الباص';

    protected static ?string $pluralLabel = 'حجوزات الباص';

    protected static ?string $modelLabel = 'حجز باص';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'inventory.company',
            'customer',
            'employee',
            'account',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('busBookingTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('trip')
                            ->label('الرحلة والكمية')
                            ->icon(Heroicon::OutlinedMap)
                            ->schema([
                                Section::make('اختيار الرحلة')
                                    ->description('السعر للتذكرة والإجمالي يُحسبان تلقائياً من سعر بيع الرحلة.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Select::make('inventory_id', 'الرحلة')
                                                    ->relationship(
                                                        'inventory',
                                                        'route',
                                                        fn ($query) => $query->with(['company'])->whereHas('company')
                                                    )
                                                    ->getOptionLabelFromRecordUsing(
                                                        fn (Model $record) => ($record->company?->name ?? '—').' — '.$record->route.' ('.($record->travel_date?->format('d/m/Y') ?? '').')'
                                                    )
                                                    ->searchable(['route'])
                                                    ->required(),
                                                TextInput::make('quantity', 'عدد التذاكر')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(1)
                                                    ->required(),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('customer')
                            ->label('العميل والموظف')
                            ->icon(Heroicon::OutlinedUserGroup)
                            ->schema([
                                Section::make('العميل')
                                    ->description('إمّا اختيار عميل مسجّل، أو إدخال الاسم والهاتف لإنشاء/ربط عميل تلقائياً عند الحفظ.')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                Select::make('customer_id', 'عميل مسجّل')
                                                    ->relationship('customer', 'full_name')
                                                    ->searchable(['full_name', 'phone'])
                                                    ->live(onBlur: true)
                                                    ->nullable(),
                                                TextInput::make('customer_name', 'اسم العميل (مباشر)')
                                                    ->maxLength(255)
                                                    ->visible(fn (Get $get) => blank($get('customer_id'))),
                                                TextInput::make('customer_phone', 'هاتف العميل')
                                                    ->tel()
                                                    ->maxLength(20)
                                                    ->visible(fn (Get $get) => blank($get('customer_id'))),
                                                Select::make('employee_id', 'الموظف المسؤول')
                                                    ->relationship(
                                                        'employee',
                                                        'full_name',
                                                        fn ($query) => $query->with('user')->orderBy('full_name')
                                                    )
                                                    ->getOptionLabelFromRecordUsing(function (Model $record): string {
                                                        if (! $record instanceof Employee) {
                                                            return (string) $record->getKey();
                                                        }

                                                        return $record->full_name
                                                            ?: trim(implode(' ', array_filter([
                                                                $record->first_name,
                                                                $record->last_name,
                                                            ])))
                                                            ?: ($record->user?->name ?? '')
                                                            ?: ('موظف #'.$record->getKey());
                                                    })
                                                    ->searchable(['full_name', 'first_name', 'last_name', 'phone'])
                                                    ->nullable(),
                                                Textarea::make('notes', 'ملاحظات')
                                                    ->rows(3)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('finance')
                            ->label('الحالة والمالية')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make('بعد التسجيل')
                                    ->description('حقول للعرض عند التعديل فقط — التحديث المالي يتم من التطبيق (دفع/إلغاء).')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('unit_price')
                                                    ->label('سعر التذكرة وقت الحجز')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                TextInput::make('total_price')
                                                    ->label('الإجمالي')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                TextInput::make('paid_amount')
                                                    ->label('المدفوع')
                                                    ->numeric()
                                                    ->prefix('ج.م')
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                Select::make('payment_status', 'حالة السداد للعميل')
                                                    ->options(BusPaymentStatus::class)
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                Select::make('status', 'حالة الحجز')
                                                    ->options(BusBookingStatus::class)
                                                    ->disabled()
                                                    ->dehydrated(false),
                                                Select::make('account_id', 'حساب مرتبط')
                                                    ->relationship('account', 'name', fn ($query) => $query->where('is_active', true))
                                                    ->searchable()
                                                    ->disabled()
                                                    ->dehydrated(false),
                                            ]),
                                    ])
                                    ->visibleOn('edit'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id', 'رقم الحجز')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('inventory.company.name', 'الشركة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('inventory.route', 'المسار')
                    ->limit(20)
                    ->searchable(),

                TextColumn::make('travel_date', 'تاريخ السفر')
                    ->getStateUsing(fn ($record) => $record->inventory?->travel_date?->format('d/m/Y'))
                    ->sortable(),

                TextColumn::make('customer.full_name', 'العميل')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('quantity', 'عدد التذاكر')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('unit_price', 'سعر التذكرة')
                    ->money('EGP')
                    ->sortable(),

                TextColumn::make('total_price', 'الإجمالي')
                    ->money('EGP')
                    ->sortable()
                    ->color('success'),

                TextColumn::make('paid_amount', 'المدفوع')
                    ->money('EGP')
                    ->toggleable(),

                TextColumn::make('profit', 'الربح')
                    ->money('EGP')
                    ->sortable()
                    ->color('success'),

                TextColumn::make('payment_status')
                    ->label('سداد العميل')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BusPaymentStatus ? $state->label() : (string) $state)
                    ->color(function ($state): string {
                        $s = $state instanceof BusPaymentStatus ? $state : BusPaymentStatus::tryFrom((string) $state);

                        return match ($s) {
                            BusPaymentStatus::Pending => 'warning',
                            BusPaymentStatus::Partial => 'info',
                            BusPaymentStatus::Paid => 'success',
                            BusPaymentStatus::Overdue => 'danger',
                            default => 'gray',
                        };
                    }),

                BadgeColumn::make('status', 'الحالة')
                    ->colors([
                        'pending' => 'warning',
                        'paid' => 'success',
                        'cancelled' => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state instanceof BusBookingStatus ? $state->value : (string) $state) {
                        'pending' => 'معلق',
                        'paid' => 'مدفوع',
                        'cancelled' => 'ملغي',
                        default => (string) $state,
                    }),

                TextColumn::make('employee.full_name', 'الموظف')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('created_at', 'تاريخ الحجز')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('status', 'الحالة')
                    ->options(BusBookingStatus::class),

                SelectFilter::make('payment_status', 'سداد العميل')
                    ->options(BusPaymentStatus::class),

                SelectFilter::make('company_id', 'الشركة')
                    ->placeholder('اختر الشركة')
                    ->options(fn () => \App\Models\Bus\BusCompany::orderBy('name')->pluck('name', 'id')->toArray())
                    ->query(fn ($query, $value) => $query->whereHas('inventory', fn ($q) => $q->where('company_id', $value))),

                SelectFilter::make('customer_id', 'العميل')
                    ->relationship('customer', 'full_name')
                    ->searchable(),

                SelectFilter::make('employee_id', 'الموظف')
                    ->relationship('employee', 'full_name', fn ($query) => $query->with('user'))
                    ->getOptionLabelFromRecordUsing(function (Model $record): string {
                        if (! $record instanceof Employee) {
                            return (string) $record->getKey();
                        }

                        return $record->full_name
                            ?: trim(implode(' ', array_filter([
                                $record->first_name,
                                $record->last_name,
                            ])))
                            ?: ($record->user?->name ?? '')
                            ?: ('موظف #'.$record->getKey());
                    })
                    ->searchable(),

                Filter::make('inventory_travel_date')
                    ->label('تاريخ السفر')
                    ->schema([
                        DatePicker::make('on')
                            ->label('اليوم')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        if (blank($data['on'] ?? null)) {
                            return;
                        }
                        $query->whereHas(
                            'inventory',
                            fn (Builder $inv) => $inv->whereDate('travel_date', $data['on'])
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
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
            'index' => Pages\ManageBusBookings::route('/'),
        ];
    }
}
