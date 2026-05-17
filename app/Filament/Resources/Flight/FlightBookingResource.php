<?php

namespace App\Filament\Resources\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\FlightPaymentMethod;
use App\Filament\Resources\Flight\FlightBookingResource\Pages;
use App\Models\Account;
use App\Models\Airport;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FlightBookingResource extends Resource
{
    protected static ?string $model = FlightBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-airplane';

    protected static ?string $navigationLabel = 'حجوزات الطيران';

    protected static ?string $modelLabel = 'حجز طيران';

    protected static ?string $pluralModelLabel = 'حجوزات الطيران';

    protected static string|\UnitEnum|null $navigationGroup = 'حجوزات';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // === Section 1: العميل ===
                Forms\Components\Section::make('بيانات العميل')
                    ->description('اختر العميل أو أنشئ جديد')
                    ->schema([
                        Forms\Components\Select::make('customer_id')
                            ->label('العميل')
                            ->options(Customer::all()->pluck('full_name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('full_name')
                                    ->label('الاسم الكامل')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('رقم الهاتف')
                                    ->required()
                                    ->tel()
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('national_id')
                                    ->label('الرقم القومي')
                                    ->maxLength(14),
                                Forms\Components\TextInput::make('city')
                                    ->label('المدينة')
                                    ->maxLength(255),
                                Forms\Components\Select::make('customer_tier')
                                    ->label('التصنيف')
                                    ->options([
                                        'STANDARD' => 'عميل عادي',
                                        'PREMIUM' => 'عميل مميز',
                                        'AGENT' => 'وكيل',
                                    ])
                                    ->default('STANDARD'),
                                Forms\Components\Textarea::make('notes')
                                    ->label('ملاحظات')
                                    ->rows(2),
                            ])
                            ->editOptionForm([
                                Forms\Components\TextInput::make('full_name')
                                    ->label('الاسم الكامل')
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label('رقم الهاتف')
                                    ->required()
                                    ->tel()
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('national_id')
                                    ->label('الرقم القومي')
                                    ->maxLength(14),
                                Forms\Components\TextInput::make('city')
                                    ->label('المدينة')
                                    ->maxLength(255),
                                Forms\Components\Select::make('customer_tier')
                                    ->label('التصنيف')
                                    ->options([
                                        'STANDARD' => 'عميل عادي',
                                        'PREMIUM' => 'عميل مميز',
                                        'AGENT' => 'وكيل',
                                    ])
                                    ->default('STANDARD'),
                                Forms\Components\Textarea::make('notes')
                                    ->label('ملاحظات')
                                    ->rows(2),
                            ]),
                    ]),

                // === Section 2: هيكل الحجز (3 مستويات) ===
                Forms\Components\Section::make('هيكل الحجز')
                    ->description('اختر النظام ثم الشركة ثم المجموعة')
                    ->schema([
                        Forms\Components\Select::make('flight_system_id')
                            ->label('النظام (System)')
                            ->options(FlightSystem::all()->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, $state) {
                                $set('flight_carrier_id', null);
                                $set('flight_group_id', null);
                            }),

                        Forms\Components\Select::make('flight_carrier_id')
                            ->label('الشركة (Carrier)')
                            ->options(function (callable $get) {
                                $systemId = $get('flight_system_id');
                                if (! $systemId) {
                                    return [];
                                }

                                return FlightCarrier::where('flight_system_id', $systemId)
                                    ->active()
                                    ->get()
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                $carrierId = $get('flight_carrier_id');
                                if ($carrierId) {
                                    $carrier = FlightCarrier::find($carrierId);
                                    if ($carrier) {
                                        $set('currency', $carrier->currency);
                                    }
                                }
                                $set('flight_group_id', null);
                            })
                            ->hidden(fn (callable $get) => ! $get('flight_system_id')),

                        Forms\Components\Select::make('flight_group_id')
                            ->label('المجموعة (Group)')
                            ->options(function (callable $get) {
                                $carrierId = $get('flight_carrier_id');
                                if (! $carrierId) {
                                    return [];
                                }

                                return FlightGroup::where('flight_carrier_id', $carrierId)
                                    ->active()
                                    ->get()
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->reactive()
                            ->hidden(fn (callable $get) => ! $get('flight_carrier_id'))
                            ->helperText('اختياري - يمكنك تركه فارغاً'),
                    ])->columns(3),

                // === Section 3: بيانات الرحلة ===
                Forms\Components\Section::make('بيانات الرحلة')
                    ->description('معلومات الرحلة والمطارات')
                    ->schema([
                        Forms\Components\Select::make('from_airport_id')
                            ->label('من (مطار المغادرة)')
                            ->options(function () {
                                return Airport::active()
                                    ->get()
                                    ->mapWithKeys(function ($airport) {
                                        return [$airport->id => $airport->fullName];
                                    });
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, $state) {
                                $airport = Airport::find($state);
                                if ($airport) {
                                    $set('from_airport', $airport->iata_code);
                                    $set('origin', $airport->city_name_ar);
                                }
                            }),

                        Forms\Components\Select::make('to_airport_id')
                            ->label('إلى (مطار الوصول)')
                            ->options(function () {
                                return Airport::active()
                                    ->get()
                                    ->mapWithKeys(function ($airport) {
                                        return [$airport->id => $airport->fullName];
                                    });
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, $state) {
                                $airport = Airport::find($state);
                                if ($airport) {
                                    $set('to_airport', $airport->iata_code);
                                    $set('destination', $airport->city_name_ar);
                                }
                            }),

                        Forms\Components\DatePicker::make('departure_date')
                            ->label('تاريخ المغادرة')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        Forms\Components\TimePicker::make('departure_time')
                            ->label('وقت المغادرة')
                            ->required()
                            ->seconds(false)
                            ->native(false),

                        Forms\Components\DatePicker::make('return_date')
                            ->label('تاريخ العودة')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->hidden(fn (callable $get) => $get('trip_type') !== 'round_trip'),

                        Forms\Components\TimePicker::make('return_time')
                            ->label('وقت العودة')
                            ->seconds(false)
                            ->native(false)
                            ->hidden(fn (callable $get) => $get('trip_type') !== 'round_trip'),

                        Forms\Components\Select::make('trip_type')
                            ->label('نوع الرحلة')
                            ->options([
                                'one_way' => 'ذهاب فقط',
                                'round_trip' => 'ذهاب وعودة',
                            ])
                            ->default('one_way')
                            ->required()
                            ->reactive()
                            ->live()
                            ->afterStateUpdated(function (callable $set, $state) {
                                if ($state === 'one_way') {
                                    $set('return_date', null);
                                    $set('return_time', null);
                                }
                            }),

                        Forms\Components\TextInput::make('pnr')
                            ->label('رقم الحجز (PNR)')
                            ->maxLength(10)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                            ->placeholder('مثال: ABC123'),

                        Forms\Components\TextInput::make('airline_name')
                            ->label('شركة الطيران')
                            ->maxLength(255)
                            ->placeholder('مثال: الجزيرة، العربية'),

                        Forms\Components\TextInput::make('baggage_allowance_kg')
                            ->label('وزن الأمتعة (كجم)')
                            ->numeric()
                            ->suffix('كجم')
                            ->default(0)
                            ->minValue(0),
                    ])->columns(3),

                // === Section 4: المسافرون (3 repeaters منفصلة) ===
                Forms\Components\Section::make('المسافرون')
                    ->description('أضف المسافرين في كل فئة')
                    ->schema([
                        // البالغون
                        Forms\Components\Repeater::make('passengers_adult')
                            ->label('بالغ (أكثر من 12 سنة)')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('الاسم الكامل')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('first_name_en')
                                    ->label('الاسم الأول (إنجليزي)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['alpha']),

                                Forms\Components\TextInput::make('last_name_en')
                                    ->label('الاسم الأخير (إنجليزي)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['alpha']),

                                Forms\Components\DatePicker::make('date_of_birth')
                                    ->label('تاريخ الميلاد')
                                    ->displayFormat('d/m/Y')
                                    ->native(false)
                                    ->required()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state) {
                                        // التحقق من العمر (> 12 سنة)
                                        if ($state) {
                                            $age = Carbon::parse($state)->age;
                                            if ($age <= 12) {
                                                // \Filament\Notifications\Notification::make()
                                                //     ->warning()
                                                //     ->title('تنبيه')
                                                //     ->body('البالغ يجب أن يكون أكبر من 12 سنة')
                                                //     ->send();
                                            }
                                        }
                                    }),
                            ])
                            ->columns(4)
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->defaultItems(0),

                        // الأطفال
                        Forms\Components\Repeater::make('passengers_child')
                            ->label('طفل (من 2 لـ 12 سنة)')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('الاسم الكامل')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('first_name_en')
                                    ->label('الاسم الأول (إنجليزي)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['alpha']),

                                Forms\Components\TextInput::make('last_name_en')
                                    ->label('الاسم الأخير (إنجليزي)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['alpha']),

                                Forms\Components\DatePicker::make('date_of_birth')
                                    ->label('تاريخ الميلاد')
                                    ->displayFormat('d/m/Y')
                                    ->native(false)
                                    ->required()
                                    ->reactive(),
                            ])
                            ->columns(4)
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->defaultItems(0),

                        // الرضع
                        Forms\Components\Repeater::make('passengers_infant')
                            ->label('رضيع (أقل من 2 سنة)')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('الاسم الكامل')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('first_name_en')
                                    ->label('الاسم الأول (إنجليزي)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['alpha']),

                                Forms\Components\TextInput::make('last_name_en')
                                    ->label('الاسم الأخير (إنجليزي)')
                                    ->required()
                                    ->maxLength(255)
                                    ->rules(['alpha']),

                                Forms\Components\DatePicker::make('date_of_birth')
                                    ->label('تاريخ الميلاد')
                                    ->displayFormat('d/m/Y')
                                    ->native(false)
                                    ->required()
                                    ->reactive(),
                            ])
                            ->columns(4)
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->defaultItems(0),
                    ]),

                // === Section 5: التسعير (مزدوج) ===
                Forms\Components\Section::make('التسعير')
                    ->description('أدخل الأسعار - سيتم حساب الربح تلقائياً')
                    ->schema([
                        Forms\Components\Select::make('currency')
                            ->label('عملة الحجز')
                            ->options([
                                'EGP' => 'جنيه مصري (EGP)',
                                'KWD' => 'دينار كويتي (KWD)',
                                'SAR' => 'ريال سعودي (SAR)',
                                'USD' => 'دولار أمريكي (USD)',
                            ])
                            ->default('EGP')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (callable $set, $state) {
                                // إخفاء/إظهار حقول العملة الأجنبية
                                $set('show_foreign_currency', $state !== 'EGP');
                            }),

                        // حقول العملة الأجنبية (تظهر فقط إذا لم تكن EGP)
                        Forms\Components\Section::make('تسعير العملة الأجنبية')
                            ->schema([
                                Forms\Components\TextInput::make('purchase_price_foreign')
                                    ->label('سعر الشراء (بالعملة الأجنبية)')
                                    ->numeric()
                                    ->prefix(fn (callable $get) => $get('foreign_currency') ?? '')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (callable $set, callable $get) {
                                        $purchaseForeign = (float) $get('purchase_price_foreign');
                                        $exchangeRate = (float) $get('exchange_rate');
                                        $purchaseEGP = $purchaseForeign * $exchangeRate;
                                        $set('purchase_price_egp', $purchaseEGP);

                                        // حساب الربح
                                        $sellingPrice = (float) $get('selling_price');
                                        $profit = $sellingPrice - $purchaseEGP;
                                        $set('profit', $profit);
                                    }),

                                Forms\Components\Select::make('foreign_currency')
                                    ->label('العملة الأجنبية')
                                    ->options([
                                        'KWD' => 'دينار كويتي (KWD)',
                                        'SAR' => 'ريال سعودي (SAR)',
                                        'USD' => 'دولار أمريكي (USD)',
                                    ])
                                    ->default('KWD')
                                    ->required()
                                    ->live(),

                                Forms\Components\TextInput::make('exchange_rate')
                                    ->label('سعر الصرف')
                                    ->numeric()
                                    ->suffix('ج.م')
                                    ->default(100.0)
                                    ->step(0.01)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (callable $set, callable $get) {
                                        $purchaseForeign = (float) $get('purchase_price_foreign');
                                        $exchangeRate = (float) $get('exchange_rate');
                                        $purchaseEGP = $purchaseForeign * $exchangeRate;
                                        $set('purchase_price_egp', $purchaseEGP);

                                        // حساب الربح
                                        $sellingPrice = (float) $get('selling_price');
                                        $profit = $sellingPrice - $purchaseEGP;
                                        $set('profit', $profit);
                                    }),

                                Forms\Components\TextInput::make('purchase_price_egp')
                                    ->label('سعر الشراء (ب الجنيه المصري)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->readOnly()
                                    ->helperText('يُحسب تلقائياً = سعر الشراء × سعر الصرف'),
                            ])
                            ->columns(2)
                            ->hidden(fn (callable $get) => $get('currency') === 'EGP'),

                        // حقول الجنيه المصري
                        Forms\Components\Section::make('تسعير الجنيه المصري')
                            ->schema([
                                Forms\Components\TextInput::make('purchase_price')
                                    ->label('سعر الشراء (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->hidden(fn (callable $get) => $get('currency') !== 'EGP')
                                    ->afterStateUpdated(function (callable $set, callable $get) {
                                        $purchasePrice = (float) $get('purchase_price');
                                        $sellingPrice = (float) $get('selling_price');
                                        $profit = $sellingPrice - $purchasePrice;
                                        $set('profit', $profit);
                                    }),

                                Forms\Components\TextInput::make('purchase_price')
                                    ->label('سعر الشراء (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->hidden(fn (callable $get) => $get('currency') === 'EGP')
                                    ->afterStateUpdated(function (callable $set, callable $get) {
                                        $purchasePrice = (float) $get('purchase_price_egp') ?? 0;
                                        $sellingPrice = (float) $get('selling_price');
                                        $profit = $sellingPrice - $purchasePrice;
                                        $set('profit', $profit);
                                    }),

                                Forms\Components\TextInput::make('selling_price')
                                    ->label('سعر البيع (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->default(0)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (callable $set, callable $get) {
                                        $purchasePrice = (float) ($get('currency') === 'EGP'
                                            ? $get('purchase_price')
                                            : $get('purchase_price_egp')
                                        );
                                        $sellingPrice = (float) $get('selling_price');
                                        $profit = $sellingPrice - $purchasePrice;
                                        $set('profit', $profit);
                                    }),

                                Forms\Components\TextInput::make('profit')
                                    ->label('الربح (ج.م)')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->readOnly()
                                    ->default(0)
                                    ->badge()
                                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger'),
                            ])
                            ->columns(4),
                    ]),

                // === Section 6: الدفع ===
                Forms\Components\Section::make('بيانات الدفع')
                    ->description('اختر طريقة الدفع المناسبة')
                    ->schema([
                        Forms\Components\Select::make('account_id')
                            ->label('حساب الخزنة')
                            ->options(Account::where('is_active', true)
                                ->get()
                                ->mapWithKeys(function ($account) {
                                    return [$account->id => "{$account->name} ({$account->currency})"];
                                }))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('اختر الحساب الذي سيتم إضافة المبلغ إليه'),

                        Forms\Components\Select::make('payment_method')
                            ->label('طريقة الدفع')
                            ->options(FlightPaymentMethod::forDropdown())
                            ->default(FlightPaymentMethod::Cash->value)
                            ->required()
                            ->live()
                            ->reactive(),

                        // حقول إضافية للبنك
                        Forms\Components\TextInput::make('bank_name')
                            ->label('اسم البنك')
                            ->maxLength(255)
                            ->placeholder('مثال: بنك مصر، الأهلي')
                            ->hidden(fn (callable $get) => $get('payment_method') !== 'bank_transfer'),

                        Forms\Components\TextInput::make('account_holder_name')
                            ->label('اسم صاحب الحساب')
                            ->maxLength(255)
                            ->hidden(fn (callable $get) => $get('payment_method') !== 'bank_transfer'),

                        // حقول إضافية للمحفظة
                        Forms\Components\TextInput::make('wallet_number')
                            ->label('رقم المحفظة')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('مثال: 01xxxxxxxxx')
                            ->hidden(fn (callable $get) => ! in_array($get('payment_method'), ['cash_wallet'])),

                        Forms\Components\TextInput::make('wallet_holder')
                            ->label('اسم صاحب المحفظة')
                            ->maxLength(255)
                            ->hidden(fn (callable $get) => ! in_array($get('payment_method'), ['cash_wallet'])),

                        // حقول إضافية للبريد
                        Forms\Components\TextInput::make('postal_office')
                            ->label('مكتب البريد')
                            ->maxLength(255)
                            ->hidden(fn (callable $get) => $get('payment_method') !== 'postal_transfer'),
                    ])->columns(3),

                // === Section 7: ملاحظات ===
                Forms\Components\Section::make('معلومات إضافية')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(3)
                            ->placeholder('أي ملاحظات إضافية عن الحجز...'),

                        Forms\Components\Select::make('status')
                            ->label('الحالة')
                            ->options(FlightBookingStatus::forDropdown())
                            ->default(FlightBookingStatus::PENDING->value)
                            ->required()
                            ->disabled(fn (string $context): bool => $context === 'edit')
                            ->helperText('لا يمكن تغيير الحالة بعد إنشاء الحجز'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): ?string => $record->customer?->phone)
                    ->wrap(),

                Tables\Columns\TextColumn::make('flightSystem.name')
                    ->label('النظام')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('flightCarrier.name')
                    ->label('الشركة')
                    ->badge()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('flightGroup.name')
                    ->label('المجموعة')
                    ->badge()
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('origin')
                    ->label('من')
                    ->searchable()
                    ->description(fn ($record): ?string => $record->from_airport),

                Tables\Columns\TextColumn::make('destination')
                    ->label('إلى')
                    ->searchable()
                    ->description(fn ($record): ?string => $record->to_airport),

                Tables\Columns\TextColumn::make('departure_date')
                    ->label('تاريخ السفر')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn ($record): ?string => $record->departure_time),

                Tables\Columns\TextColumn::make('selling_price')
                    ->label('سعر البيع')
                    ->money(fn ($record) => $record->currency ?? 'EGP')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('profit')
                    ->label('الربح')
                    ->money('EGP')
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => $record->profit >= 0 ? 'success' : 'danger'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'warning' => FlightBookingStatus::PENDING->value,
                        'success' => FlightBookingStatus::CONFIRMED->value,
                        'danger' => FlightBookingStatus::CANCELLED->value,
                        'gray' => FlightBookingStatus::REFUNDED->value,
                    ])
                    ->formatStateUsing(fn ($state): string => FlightBookingStatus::from($state)->label()),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(FlightBookingStatus::forDropdown()),

                Tables\Filters\SelectFilter::make('flight_system_id')
                    ->label('النظام')
                    ->relationship('flightSystem', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('flight_carrier_id')
                    ->label('الشركة')
                    ->relationship('flightCarrier', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('flight_group_id')
                    ->label('المجموعة')
                    ->relationship('flightGroup', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('departure_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('من'),
                        Forms\Components\DatePicker::make('until')
                            ->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('departure_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('departure_date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('refund')
                    ->label('إصدار استرجاع')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('warning')
                    ->url(fn (FlightBooking $record): string => RefundRequestResource::getUrl('create', ['flight_booking_id' => $record->id]))
                    ->visible(fn (FlightBooking $record): bool => ! in_array($record->status?->value ?? $record->status, ['CANCELLED', 'REFUNDED'])),
                \Filament\Actions\Action::make('modify')
                    ->label('طلب تعديل')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('info')
                    ->url(fn (FlightBooking $record): string => TicketModificationResource::getUrl('create', ['booking_id' => $record->id]))
                    ->visible(fn (FlightBooking $record): bool => ! in_array($record->status?->value ?? $record->status, ['CANCELLED', 'REFUNDED'])),
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            'customer',
            'flightSystem',
            'flightCarrier',
            'flightGroup',
            'fromAirport',
            'toAirport',
            'passengers',
            'payments',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightBookings::route('/'),
            'create' => Pages\CreateFlightBooking::route('/create'),
            'edit' => Pages\EditFlightBooking::route('/{record}/edit'),
            'view' => Pages\ViewFlightBooking::route('/{record}'),
        ];
    }
}
