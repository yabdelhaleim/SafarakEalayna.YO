<?php

namespace App\Filament\Admin\Resources\FlightBookings;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\FlightBookings\Pages\CreateFlightBooking;
use App\Filament\Admin\Resources\FlightBookings\Pages\EditFlightBooking;
use App\Filament\Admin\Resources\FlightBookings\Pages\ListFlightBookings;
use App\Models\Flight\FlightBooking;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FlightBookingResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = FlightBooking::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'حجوزات الطيران';

    protected static ?string $pluralLabel = 'حجوزات الطيران';

    protected static ?string $modelLabel = 'حجز طيران';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'booking_reference';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('flightBookingTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('identifiers')
                            ->label('المرجع والعميل')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->schema([
                                Section::make('بيانات أساسية')
                                    ->description('ما يظهر في Vue: رقم الحجز، PNR، العميل، الحالة')
                                    ->schema([
                                        TextInput::make('booking_reference')
                                            ->label('رقم المرجع (قديم / PNR داخلي)')
                                            ->required()
                                            ->maxLength(255)
                                            ->unique(ignoreRecord: true),
                                        TextInput::make('booking_number')
                                            ->label('رقم الحجز (FLT-…)')
                                            ->maxLength(255),
                                        TextInput::make('pnr')
                                            ->label('PNR')
                                            ->maxLength(255),
                                        Select::make('customer_id')
                                            ->label('العميل')
                                            ->relationship('customer', 'full_name')
                                            ->searchable(['full_name', 'phone'])
                                            ->required(),
                                        Select::make('status')
                                            ->label('الحالة')
                                            ->options(\App\Enums\FlightBookingStatus::class)
                                            ->required(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('route')
                            ->label('المسار والرحلة')
                            ->icon(Heroicon::OutlinedMap)
                            ->schema([
                                Section::make('المسار')
                                    ->schema([
                                        TextInput::make('origin')
                                            ->label('من (رمز)')
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('destination')
                                            ->label('إلى (رمز)')
                                            ->required()
                                            ->maxLength(10),
                                        TextInput::make('from_airport')
                                            ->label('مطار المغادرة (نص)')
                                            ->maxLength(10),
                                        TextInput::make('to_airport')
                                            ->label('مطار الوصول (نص)')
                                            ->maxLength(10),
                                        TextInput::make('airline')
                                            ->label('شركة الطيران (رمز/اسم مختصر)')
                                            ->required(),
                                        TextInput::make('airline_name')
                                            ->label('اسم شركة الطيران')
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),
                                Section::make('المواعيد')
                                    ->schema([
                                        DatePicker::make('departure_date')
                                            ->label('تاريخ المغادرة')
                                            ->required(),
                                        TimePicker::make('departure_time')
                                            ->label('وقت المغادرة'),
                                        DatePicker::make('return_date')
                                            ->label('تاريخ العودة'),
                                        TimePicker::make('return_time')
                                            ->label('وقت العودة'),
                                        Select::make('trip_type')
                                            ->label('نوع الرحلة')
                                            ->options(\App\Enums\TripType::class)
                                            ->required(),
                                        TextInput::make('passenger_count')
                                            ->label('عدد الركاب')
                                            ->numeric()
                                            ->default(1)
                                            ->required(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('commercial')
                            ->label('التسعير والملاحظات')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make('الأرقام المالية')
                                    ->description('متطابقة مع أعمدة جدول flight_bookings التي يعرضها Vue')
                                    ->schema([
                                        TextInput::make('purchase_price')
                                            ->label('سعر الشراء')
                                            ->numeric()
                                            ->default(0),
                                        TextInput::make('selling_price')
                                            ->label('سعر البيع')
                                            ->numeric()
                                            ->default(0),
                                        TextInput::make('profit')
                                            ->label('الربح')
                                            ->numeric()
                                            ->default(0),
                                    ])
                                    ->columns(3),
                                Section::make('تشغيلي')
                                    ->schema([
                                        TextInput::make('agent_name')
                                            ->label('اسم الوكيل')
                                            ->maxLength(255),
                                        Textarea::make('notes')
                                            ->label('ملاحظات')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('settlement')
                            ->label('النظام والتسوية')
                            ->icon(Heroicon::OutlinedRectangleStack)
                            ->schema([
                                Section::make('ربط الحجز')
                                    ->description('نفس الحقول التي يرسلها تطبيق الحجز (سيستم / ساين / مجموعة / مصدر الخصم)')
                                    ->schema([
                                        Select::make('flight_system_id')
                                            ->label('نظام الحجز')
                                            ->relationship('flightSystem', 'name')
                                            ->searchable()
                                            ->preload(),
                                        Select::make('flight_carrier_id')
                                            ->label('شركة الطيران')
                                            ->relationship('flightCarrier', 'name')
                                            ->searchable()
                                            ->preload(),
                                        Select::make('flight_group_id')
                                            ->label('المجموعة')
                                            ->relationship('flightGroup', 'name')
                                            ->searchable()
                                            ->preload(),
                                        Select::make('purchase_balance_source')
                                            ->label('خصم تكلفة الشراء من')
                                            ->options([
                                                'carrier' => 'رصيد الساين (شركة الطيران)',
                                                'system' => 'رصيد النظام (سيستم الحجز)',
                                            ])
                                            ->native(false),
                                        Select::make('employee_id')
                                            ->label('الموظف')
                                            ->relationship('employee', 'full_name')
                                            ->searchable(['full_name']),
                                        TextInput::make('baggage_allowance_kg')
                                            ->label('الأمتعة (كجم)')
                                            ->numeric()
                                            ->default(0),
                                        TextInput::make('currency')
                                            ->label('عملة التسعير')
                                            ->maxLength(3)
                                            ->default('EGP'),
                                    ])
                                    ->columns(2),
                            ]),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'flightSystem', 'flightCarrier', 'flightGroup', 'employee']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('booking_reference')
            ->columns([
                TextColumn::make('booking_reference')
                    ->label('المرجع')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge(),
                TextColumn::make('airline')
                    ->label('الطيران')
                    ->toggleable(),
                TextColumn::make('origin')
                    ->label('من')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('destination')
                    ->label('إلى')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('purchase_balance_source')
                    ->label('خصم الشراء من')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('selling_price')
                    ->label('البيع')
                    ->money('egp')
                    ->sortable(),
                TextColumn::make('profit')
                    ->label('الربح')
                    ->money('egp')
                    ->sortable()
                    ->color(fn ($state): string => (float) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('departure_date')
                    ->label('التاريخ')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('modify')
                    ->label('طلب تعديل')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->url(fn ($record): string => \App\Filament\Admin\Resources\TicketModifications\TicketModificationResource::getUrl('create') . '?booking_id=' . $record->id),
                \Filament\Tables\Actions\EditAction::make()->modal(false),
            ])
            ->bulkActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlightBookings::route('/'),
            'create' => CreateFlightBooking::route('/create'),
            'edit' => EditFlightBooking::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
