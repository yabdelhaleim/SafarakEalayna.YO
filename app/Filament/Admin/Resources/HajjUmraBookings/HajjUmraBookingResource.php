<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings;

use App\Enums\HajjUmraPaymentMethod;
use App\Enums\HajjUmraStatus;
use App\Rules\HajjUmraLiquidityAccount;
use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource\Widgets\HajjUmraStats;
use App\Filament\Admin\Resources\HajjUmraBookings\Pages\CreateHajjUmraBooking;
use App\Filament\Admin\Resources\HajjUmraBookings\Pages\EditHajjUmraBooking;
use App\Filament\Admin\Resources\HajjUmraBookings\Pages\ListHajjUmraBookings;
use App\Filament\Admin\Resources\HajjUmraBookings\Pages\ViewHajjUmraBooking;
use App\Models\Account;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Services\HajjUmra\HajjUmraBookingService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class HajjUmraBookingResource extends Resource
{
    protected static ?string $model = HajjUmraBooking::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'حجوزات الحج والعمرة';

    protected static ?string $pluralLabel = 'حجوزات الحج والعمرة';

    protected static ?string $modelLabel = 'حجز حج/عمرة';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('العميل والبرنامج')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('customer_id')
                            ->label('العميل')
                            ->relationship('customer', 'full_name')
                            ->searchable(['full_name', 'phone', 'passport_number'])
                            ->preload()
                            ->required(),

                        Select::make('companion_customer_id')
                            ->label('المرافق (اختياري)')
                            ->relationship('companion', 'full_name')
                            ->searchable(['full_name', 'phone', 'passport_number'])
                            ->preload()
                            ->live(),

                        Select::make('program_id')
                            ->label('البرنامج')
                            ->relationship('program', 'program_name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if (! $state) {
                                    return;
                                }
                                $p = Program::find($state);
                                if ($p && (float) $p->default_purchase_price > 0) {
                                    $set('purchase_price', (float) $p->default_purchase_price);
                                }
                                if ($p && (float) $p->default_selling_price > 0) {
                                    $set('selling_price', (float) $p->default_selling_price);
                                }
                            }),
                    ]),
                ])->columnSpanFull(),

            Section::make('المرافق والمورّد')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('companion_purchase_price')
                            ->label('سعر شراء المرافق')
                            ->numeric()
                            ->prefix('ج.م')
                            ->step(0.01)
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => filled($get('companion_customer_id'))),

                        TextInput::make('companion_selling_price')
                            ->label('سعر بيع المرافق')
                            ->numeric()
                            ->prefix('ج.م')
                            ->step(0.01)
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => filled($get('companion_customer_id'))),

                        Select::make('supplier_id')
                            ->label('المورّد (وكيل / شركة)')
                            ->options(fn () => UmrahSupplier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload(),

                        Select::make('accommodation_choice')
                            ->label('خيار التسكين')
                            ->options([
                                'standard' => 'تسكين عادي (مشترك)',
                                'private' => 'غرفة خاصة',
                            ])
                            ->default('standard')
                            ->live(),

                        TextInput::make('accommodation_extra_charge')
                            ->label('رسوم السكن الخاص الإضافية')
                            ->numeric()
                            ->prefix('ج.م')
                            ->step(0.01)
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => $get('accommodation_choice') === 'private'),
                    ]),
                ])->columnSpanFull(),

            Section::make('التسعير')
                ->description('سعر الشراء = ما يستحق على المكتب — سعر البيع = ما يحصّل من العميل — الربح = البيع − الشراء (يُحسب آلياً).')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('purchase_price')
                            ->label('سعر الشراء (التكلفة)')
                            ->numeric()
                            ->required()
                            ->prefix('ج.م')
                            ->step(0.01)
                            ->minValue(0),

                        TextInput::make('selling_price')
                            ->label('سعر البيع')
                            ->numeric()
                            ->required()
                            ->prefix('ج.م')
                            ->step(0.01)
                            ->minValue(0),

                        Toggle::make('per_person')
                            ->label('السعر للفرد')
                            ->default(true)
                            ->inline(false),
                    ]),
                ])->columnSpanFull(),

            Section::make('شبكة تسعير الأسرة')
                ->description('اختياري — لتوثيق تفصيل الفئات (بالغ / طفل / رضيع).')
                ->schema([
                    Grid::make(4)->schema([
                        TextInput::make('passenger_adult_count')->label('بالغ — العدد')->numeric()->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_adult_unit_price')->label('بالغ — سعر الفرد')->numeric()->prefix('ج.م')->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_child_with_bed_count')->label('طفل بسرير — العدد')->numeric()->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_child_with_bed_unit_price')->label('طفل بسرير — سعر الفرد')->numeric()->prefix('ج.م')->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_child_no_bed_count')->label('طفل بدون سرير — العدد')->numeric()->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_child_no_bed_unit_price')->label('طفل بدون سرير — سعر الفرد')->numeric()->prefix('ج.م')->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_infant_count')->label('رضيع — العدد')->numeric()->minValue(0)->default(0)->dehydrated(),
                        TextInput::make('passenger_infant_unit_price')->label('رضيع — سعر الفرد')->numeric()->prefix('ج.م')->minValue(0)->default(0)->dehydrated(),
                    ]),
                ])->columnSpanFull(),

            Section::make('المحاسبة والدفع')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('account_id')
                            ->label('حساب التسوية / الخزينة')
                            ->relationship('account', 'name', fn (Builder $query) => HajjUmraLiquidityAccount::applyLiquidityScope($query))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('من إعدادات الحسابات (Filament > الحسابات).'),

                        Select::make('status')
                            ->label('حالة الحجز')
                            ->options(HajjUmraStatus::forDropdown())
                            ->default(HajjUmraStatus::Confirmed->value)
                            ->required(),

                        Select::make('employee_id')
                            ->label('الموظف القائم بالحجز')
                            ->relationship('employee', 'name')
                            ->searchable()
                            ->preload(),

                        TextInput::make('agent_name')
                            ->label('اسم الموظف (نص)')
                            ->maxLength(150),
                    ]),
                ])->columnSpanFull(),

            Section::make('الدفعة الأولية')
                ->description('اختياري — تُسجَّل مع إنشاء الحجز عبر القيد المحاسبي.')
                ->schema([
                    Toggle::make('register_initial_payment')
                        ->label('تسجيل دفعة أولية الآن')
                        ->default(false)
                        ->live()
                        ->dehydrated(),

                    Grid::make(2)->schema([
                        TextInput::make('initial_payment_amount')
                            ->label('مبلغ الدفعة')
                            ->numeric()
                            ->prefix('ج.م')
                            ->step(0.01)
                            ->minValue(0)
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => (bool) $get('register_initial_payment')),

                        Select::make('initial_payment_method')
                            ->label('طريقة الدفع')
                            ->options(HajjUmraPaymentMethod::forDropdown())
                            ->default(HajjUmraPaymentMethod::Cash->value)
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => (bool) $get('register_initial_payment')),

                        Select::make('initial_payment_account_id')
                            ->label('حساب التحصيل')
                            ->options(fn () => HajjUmraLiquidityAccount::applyLiquidityScope(Account::query())->pluck('name', 'id')->all())
                            ->searchable()
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => (bool) $get('register_initial_payment')),

                        DatePicker::make('initial_payment_date')
                            ->label('تاريخ الدفع')
                            ->default(now())
                            ->native(false)
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => (bool) $get('register_initial_payment')),

                        TextInput::make('initial_payment_reference')
                            ->label('رقم المرجع')
                            ->maxLength(100)
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => (bool) $get('register_initial_payment')),

                        TextInput::make('initial_payment_paid_by')
                            ->label('المدفوع بواسطة')
                            ->maxLength(150)
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => (bool) $get('register_initial_payment')),
                    ]),
                ])->columnSpanFull(),

            Section::make('ملاحظات وأمتعة')
                ->schema([
                    Grid::make(2)->schema([
                        Textarea::make('notes')->label('ملاحظات')->rows(3)->maxLength(1000),
                        TextInput::make('baggage')
                            ->label('الأمتعة والوزن')
                            ->placeholder('مثال: 23 كيلو + حقيبة يد')
                            ->maxLength(255),
                    ]),
                ])->columnSpanFull(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'program', 'account', 'employee']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('customer.full_name')->label('العميل')->searchable()->wrap(),
                TextColumn::make('customer.phone')->label('الهاتف')->toggleable(),
                TextColumn::make('program.program_name')->label('البرنامج')->searchable()->wrap(),
                TextColumn::make('program.program_type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $s) => $s === 'hajj' ? 'حج' : ($s === 'umra' ? 'عمرة' : '-'))
                    ->color(fn (?string $s) => $s === 'hajj' ? 'success' : 'info'),
                TextColumn::make('purchase_price')->label('الشراء')->money('egp')->sortable(),
                TextColumn::make('selling_price')->label('البيع')->money('egp')->sortable(),
                TextColumn::make('profit')->label('الربح')->money('egp')->color('success')->sortable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof HajjUmraStatus ? $state->label() : (HajjUmraStatus::tryFrom((string) $state)?->label() ?? $state))
                    ->color(fn ($state) => $state instanceof HajjUmraStatus ? $state->color() : (HajjUmraStatus::tryFrom((string) $state)?->color() ?? 'secondary')),
                TextColumn::make('account.name')->label('الحساب')->toggleable(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(HajjUmraStatus::forDropdown()),
                SelectFilter::make('program_id')->label('البرنامج')
                    ->relationship('program', 'program_name')->searchable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->successNotificationTitle('تم تحديث الحجز'),
                Action::make('addPayment')
                    ->label('تسجيل دفعة')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->schema([
                        TextInput::make('amount')->label('المبلغ')->numeric()->required()->prefix('ج.م'),
                        Select::make('account_id')->label('الحساب')
                            ->relationship('account', 'name', fn (Builder $query) => HajjUmraLiquidityAccount::applyLiquidityScope($query))
                            ->searchable()->preload()->required(),
                        Select::make('payment_method')->label('طريقة الدفع')->required()
                            ->options(HajjUmraPaymentMethod::forDropdown()),
                        DatePicker::make('payment_date')->label('تاريخ الدفع')->default(now())->native(false),
                        TextInput::make('reference')->label('رقم المرجع')->maxLength(100),
                        TextInput::make('paid_by')->label('المدفوع بواسطة')->maxLength(150),
                    ])
                    ->action(function (HajjUmraBooking $record, array $data) {
                        app(HajjUmraBookingService::class)->addPayment($record, $data);
                        Notification::make()->title('تم تسجيل الدفعة')->success()->send();
                    }),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHajjUmraBookings::route('/'),
            'create' => CreateHajjUmraBooking::route('/create'),
            'view' => ViewHajjUmraBooking::route('/{record}'),
            'edit' => EditHajjUmraBooking::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            HajjUmraStats::class,
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
