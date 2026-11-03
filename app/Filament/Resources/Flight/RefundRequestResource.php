<?php

namespace App\Filament\Resources\Flight;

use App\Filament\Resources\Flight\RefundRequestResource\Pages;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\RefundRequest;
use App\Models\Treasury;
use App\Services\Flight\RefundService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RefundRequestResource extends Resource
{
    protected static ?string $model = RefundRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'طلبات الاسترجاع';

    protected static ?string $modelLabel = 'طلب استرجاع';

    protected static ?string $pluralModelLabel = 'طلبات الاسترجاع';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('معلومات الحجز المسترد')
                    ->schema([
                        Forms\Components\Select::make('flight_booking_id')
                            ->label('الحجز الأصلي')
                            ->relationship('booking', 'booking_number')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                if (! $state) {
                                    return;
                                }
                                $booking = FlightBooking::find($state);
                                if (! $booking) {
                                    return;
                                }
                                $currency = $booking->original_currency ?: ($booking->currency ?: 'EGP');
                                $amount = (float) ($booking->original_amount ?: $booking->selling_price);
                                $rate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));

                                $set('original_currency', $currency);
                                $set('original_amount', $amount);
                                $set('refund_currency', $currency);
                                $set('refund_exchange_rate', $rate);

                                $cost = (float) ($booking->cost ?: ($booking->buying_price ?: 0));
                                $set('supplier_cost', $cost);
                                $set('supplier_refund_amount', $cost);
                                $set('supplier_refund_loss', 0);

                                // الحساب الفوري
                                $fee = 0;
                                $refund = $amount - $fee;
                                $baseRefund = $refund * $rate;
                                $baseAfterFeeAtBookingRate = $refund * $rate;
                                $diff = $baseRefund - $baseAfterFeeAtBookingRate;

                                $set('refund_amount', $refund);
                                $set('base_currency_refund', $baseRefund);
                                $set('currency_difference', $diff);
                            }),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('original_currency')
                                    ->label('عملة الحجز الأصلية')
                                    ->required()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('original_amount')
                                    ->label('المبلغ الأصلي')
                                    ->required()
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\Select::make('refund_case')
                                    ->label('نوع / حالة الاسترداد')
                                    ->options([
                                        'customer_voluntary' => 'الحالة 1: إلغاء اختياري من العميل (غرامة طيران + عمولة وكالة)',
                                        'airline_cancelled' => 'الحالة 2: إلغاء الرحلة من شركة الطيران',
                                        'default' => 'استرداد عام / قياسي',
                                    ])
                                    ->default('default')
                                    ->live(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('airline_penalty')
                                    ->label('غرامة شركة الطيران (على العميل)')
                                    ->numeric()
                                    ->default(0)
                                    ->visible(fn (Forms\Get $get): bool => $get('refund_case') === 'customer_voluntary'),

                                Forms\Components\TextInput::make('agency_commission')
                                    ->label('عمولة الوكالة')
                                    ->numeric()
                                    ->default(0)
                                    ->visible(fn (Forms\Get $get): bool => in_array($get('refund_case'), ['customer_voluntary', 'airline_cancelled'])),

                                Forms\Components\TextInput::make('cancellation_fee')
                                    ->label('إجمالي رسوم الإلغاء')
                                    ->numeric()
                                    ->default(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                        self::recalculateTotals($get, $set);
                                    }),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Toggle::make('apply_commission')
                                    ->label('تطبيق عمولة الوكالة في حالة إلغاء الطيران')
                                    ->default(false)
                                    ->visible(fn (Forms\Get $get): bool => $get('refund_case') === 'airline_cancelled'),

                                Forms\Components\Toggle::make('non_refundable_taxes_on_customer')
                                    ->label('تحميل الضرائب غير المستردة على العميل (إن وُجدت)')
                                    ->helperText('إذا لم تُفعّل، تتحملها الوكالة في حساب خسائر استرداد')
                                    ->default(false)
                                    ->visible(fn (Forms\Get $get): bool => $get('refund_case') === 'airline_cancelled'),
                            ]),
                    ]),

                Forms\Components\Section::make('حسابات الاسترجاع وتوجيه العملة')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('refund_amount')
                                    ->label('الصافي المسترد')
                                    ->required()
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('refund_currency')
                                    ->label('عملة الاسترجاع الفعلية')
                                    ->required()
                                    ->maxLength(3)
                                    ->live(onBlur: true),

                                Forms\Components\TextInput::make('refund_exchange_rate')
                                    ->label('سعر الصرف للاسترجاع')
                                    ->required()
                                    ->numeric()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                        self::recalculateTotals($get, $set);
                                    }),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('base_currency_refund')
                                    ->label('المسترد بالعملة الأساسية (المحلية)')
                                    ->required()
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('currency_difference')
                                    ->label('فروقات العملة')
                                    ->required()
                                    ->numeric()
                                    ->readOnly(),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('destination')
                                    ->label('وجهة الاسترجاع')
                                    ->options([
                                        'airline_credit' => 'رصيد لدى شركة الطيران (Airline Credit)',
                                        'agency_treasury' => 'إيداع نقدي في خزينة الوكالة',
                                    ])
                                    ->required()
                                    ->live(),

                                Forms\Components\Select::make('treasury_id')
                                    ->label('خزينة الإيداع المستهدفة')
                                    ->options(function (Forms\Get $get) {
                                        $curr = $get('refund_currency');
                                        if (! $curr) {
                                            return Treasury::active()->pluck('name', 'id');
                                        }
                                        return Treasury::active()->byCurrency($curr)->pluck('name', 'id');
                                    })
                                    ->required(fn (Forms\Get $get): bool => $get('destination') === 'agency_treasury')
                                    ->visible(fn (Forms\Get $get): bool => $get('destination') === 'agency_treasury'),
                            ]),

                        Forms\Components\Select::make('status')
                            ->label('حالة الطلب')
                            ->options([
                                'pending' => 'قيد الانتظار',
                                'approved' => 'معتمد مبدئياً',
                                'processed' => 'معالج ومرحل للأرصدة',
                            ])
                            ->default('pending')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('الطرف (أ): استرداد المورد وشركة الطيران')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('supplier_cost')
                                    ->label('تكلفة التذكرة الأصلية')
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('supplier_refund_amount')
                                    ->label('المبلغ المسترد من المورد')
                                    ->numeric()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                        $cost = (float) $get('supplier_cost');
                                        $refund = (float) $get('supplier_refund_amount');
                                        $loss = max(0, $cost - $refund);
                                        $set('supplier_refund_loss', $loss);
                                    }),

                                Forms\Components\TextInput::make('supplier_refund_loss')
                                    ->label('خسارة/غرامة الاسترداد')
                                    ->numeric()
                                    ->readOnly()
                                    ->helperText('الفرق في حال رد المورد مبلغ أقل من التكلفة'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('actual_airline_refund_amount')
                                    ->label('المبلغ المسترد الفعلي من الطيران')
                                    ->numeric()
                                    ->helperText('إذا رد الطيران مبلغاً أقل بعد التنفيذ'),

                                Forms\Components\TextInput::make('refund_variance_amount')
                                    ->label('فروق استرداد (في حساب الفروق)')
                                    ->numeric()
                                    ->readOnly()
                                    ->helperText('لا يتم تحميلها على العميل تلقائياً'),
                            ]),

                        Forms\Components\Toggle::make('supplier_refund_confirmed')
                            ->label('تم تأكيد استرداد المورد بنجاح')
                            ->helperText('شرط أساسي للسماح بالصرف النقدي أو البنكي للعميل إلا بصلاحية خاصة')
                            ->default(false),
                    ]),

                Forms\Components\Section::make('الطرف (ب): تسويات وأرصدة العميل')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('customer_debt_settled_amount')
                                    ->label('المخصوم من مديونية العميل (FIFO)')
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('customer_credit_amount')
                                    ->label('رصيد دائن للعميل')
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('disbursed_amount')
                                    ->label('إجمالي المنصرف للعميل')
                                    ->numeric()
                                    ->readOnly(),

                                Forms\Components\TextInput::make('disbursement_status')
                                    ->label('حالة صرف رصيد العميل')
                                    ->readOnly(),
                            ]),
                    ]),
            ]);
    }

    protected static function recalculateTotals(Forms\Get $get, Forms\Set $set): void
    {
        $orig = (float) $get('original_amount');
        $fee = (float) $get('cancellation_fee');
        $rate = (float) $get('refund_exchange_rate');

        $bookingId = $get('flight_booking_id');
        $bookingRate = 1.0;
        if ($bookingId) {
            $booking = FlightBooking::find($bookingId);
            if ($booking) {
                $bookingRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
            }
        }

        $refund = $orig - $fee;
        $baseRefund = $refund * $rate;
        $baseAfterFeeBookingRate = $refund * $bookingRate;
        $diff = $baseRefund - $baseAfterFeeBookingRate;

        $set('refund_amount', max(0, $refund));
        $set('base_currency_refund', max(0, $baseRefund));
        $set('currency_difference', $diff);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable()
                    ->color('primary')
                    ->url(fn (RefundRequest $record): string => FlightBookingResource::getUrl('edit', ['record' => $record->flight_booking_id])),

                Tables\Columns\BadgeColumn::make('refund_case')
                    ->label('نوع الإلغاء')
                    ->colors([
                        'warning' => 'customer_voluntary',
                        'info' => 'airline_cancelled',
                        'secondary' => 'default',
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'customer_voluntary' => 'إلغاء عميل',
                        'airline_cancelled' => 'إلغاء طيران',
                        default => 'استرداد عام',
                    }),

                Tables\Columns\TextColumn::make('original_amount')
                    ->label('المبلغ الأصلي')
                    ->numeric(2)
                    ->sortable()
                    ->description(fn (RefundRequest $record): string => $record->original_currency),

                Tables\Columns\TextColumn::make('cancellation_fee')
                    ->label('رسوم الإلغاء')
                    ->numeric(2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('refund_amount')
                    ->label('الصافي المسترد')
                    ->numeric(2)
                    ->sortable()
                    ->color('success')
                    ->description(fn (RefundRequest $record): string => $record->refund_currency),

                Tables\Columns\TextColumn::make('supplier_refund_amount')
                    ->label('مسترد المورد')
                    ->numeric(2)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('supplier_refund_confirmed')
                    ->label('تأكيد المورد')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('refund_variance_amount')
                    ->label('فروق استرداد')
                    ->numeric(2)
                    ->sortable()
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('customer_credit_amount')
                    ->label('رصيد دائن للعميل')
                    ->numeric(2)
                    ->sortable()
                    ->color('info'),

                Tables\Columns\TextColumn::make('disbursed_amount')
                    ->label('المنصرف للعميل')
                    ->numeric(2)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('disbursement_status')
                    ->label('حالة الصرف')
                    ->colors([
                        'secondary' => 'none',
                        'warning' => 'unpaid',
                        'info' => 'partially_disbursed',
                        'success' => 'fully_disbursed',
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'none' => 'لا يوجد رصيد',
                        'unpaid' => 'معلق غير منصرف',
                        'partially_disbursed' => 'منصرف جزئياً',
                        'fully_disbursed' => 'منصرف كلياً',
                        default => $state ?? '—',
                    })
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('destination')
                    ->label('الوجهة')
                    ->colors([
                        'primary' => 'airline_credit',
                        'success' => 'agency_treasury',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {                        'airline_credit' => 'رصيد طيران',                        'agency_treasury' => 'خزينة الوكالة',                        default => $state,
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'approved',
                        'success' => 'processed',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {                        'pending' => 'انتظار',                        'approved' => 'معتمد',                        'processed' => 'مرحل',                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'قيد الانتظار',
                        'approved' => 'معتمد مبدئياً',
                        'processed' => 'معالج ومرحل',
                    ]),

                Tables\Filters\SelectFilter::make('destination')
                    ->label('الوجهة')
                    ->options([
                        'airline_credit' => 'رصيد طيران',
                        'agency_treasury' => 'خزينة الوكالة',
                    ]),
            ])
            ->actions([
                \Filament\Actions\Action::make('process')
                    ->label('معالجة وترحيل')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد ترحيل واعتماد الاسترجاع')
                    ->modalDescription('هل أنت متأكد من رغبتك في ترحيل هذا الاسترجاع؟ سيتم تحديث رصيد الخزينة أو رصيد الطيران بشكل نهائي وتطبيق قيود العزل المالي التام.')
                    ->visible(fn (RefundRequest $record): bool => $record->status !== 'processed')
                    ->action(function (RefundRequest $record) {
                        try {
                            $userId = Auth::id() ?: 1;
                            app(RefundService::class)->processRefundRequest($record->id, $userId);

                            Notification::make()
                                ->title('تم الترحيل بنجاح')
                                ->body('تم تحديث الأرصدة وإصدار القيود المالية وتغيير حالة الحجز بنجاح.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('فشل الترحيل')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                \Filament\Actions\Action::make('confirm_supplier')
                    ->label('تأكيد استرداد المورد')
                    ->icon('heroicon-o-check-badge')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد استرداد المورد')
                    ->modalDescription('هل تؤكد استلام / إقرار المورد أو شركة الطيران بقيمة الاسترداد؟ هذا الإجراء يسمح بصرف رصيد العميل نقداً أو بنكياً.')
                    ->visible(fn (RefundRequest $record): bool => $record->status === 'processed' && ! (bool) $record->supplier_refund_confirmed)
                    ->action(function (RefundRequest $record) {
                        try {
                            $userId = Auth::id() ?: 1;
                            app(RefundService::class)->confirmSupplierRefund($record->id, $userId);

                            Notification::make()
                                ->title('تم تأكيد استرداد المورد بنجاح')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('فشل تأكيد استرداد المورد')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                \Filament\Actions\Action::make('disburse')
                    ->label('صرف رصيد العميل')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (RefundRequest $record): bool => $record->status === 'processed' && (float) $record->customer_credit_amount > (float) $record->disbursed_amount)
                    ->form([
                        Forms\Components\Select::make('type')
                            ->label('طريقة الصرف')
                            ->options([
                                'cash_treasury' => 'رد نقدي من خزينة',
                                'bank_transfer' => 'رد بنكي / تحويل',
                                'booking_settlement' => 'تسوية في حجز تذكرة آخر',
                            ])
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('amount')
                            ->label('المبلغ المراد صرفه')
                            ->numeric()
                            ->required()
                            ->default(fn (RefundRequest $record): float => (float) $record->customer_credit_amount - (float) $record->disbursed_amount)
                            ->helperText(fn (RefundRequest $record): string => 'الرصيد الدائن المتاح: ' . number_format((float) $record->customer_credit_amount - (float) $record->disbursed_amount, 2)),

                        Forms\Components\Select::make('treasury_id')
                            ->label('الخزينة')
                            ->options(fn () => Treasury::active()->pluck('name', 'id'))
                            ->visible(fn (Forms\Get $get): bool => $get('type') === 'cash_treasury')
                            ->required(fn (Forms\Get $get): bool => $get('type') === 'cash_treasury'),

                        Forms\Components\Select::make('bank_account_id')
                            ->label('الحساب البنكي')
                            ->options(fn () => \App\Models\Account::where('type', 'bank')->pluck('name', 'id'))
                            ->visible(fn (Forms\Get $get): bool => $get('type') === 'bank_transfer')
                            ->required(fn (Forms\Get $get): bool => $get('type') === 'bank_transfer'),

                        Forms\Components\Select::make('target_flight_booking_id')
                            ->label('الحجز المستهدف للتسوية')
                            ->options(function (RefundRequest $record) {
                                $customerId = $record->booking?->customer_id;
                                if (! $customerId) {
                                    return [];
                                }
                                return FlightBooking::where('customer_id', $customerId)
                                    ->where('id', '!=', $record->flight_booking_id)
                                    ->where('payment_status', '!=', 'paid')
                                    ->pluck('booking_number', 'id');
                            })
                            ->visible(fn (Forms\Get $get): bool => $get('type') === 'booking_settlement')
                            ->required(fn (Forms\Get $get): bool => $get('type') === 'booking_settlement'),

                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات الصرف')
                            ->maxLength(500),
                    ])
                    ->action(function (RefundRequest $record, array $data) {
                        try {
                            $user = Auth::user();
                            app(RefundService::class)->disburseCustomerCredit($record->id, $data, $user);

                            Notification::make()
                                ->title('تم صرف رصيد العميل بنجاح')
                                ->body("تم صرف مبلغ {$data['amount']} بنجاح.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('فشل عملية الصرف')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefundRequests::route('/'),
            'create' => Pages\CreateRefundRequest::route('/create'),
            'edit' => Pages\EditRefundRequest::route('/{record}/edit'),
        ];
    }
}
