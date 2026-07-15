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

                                Forms\Components\TextInput::make('cancellation_fee')
                                    ->label('رسوم الإلغاء')
                                    ->numeric()
                                    ->default(0)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                        self::recalculateTotals($get, $set);
                                    }),
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
