<?php

namespace App\Filament\Resources;

use App\Models\Booking;
use App\Filament\Resources\BookingResource\Pages;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';
    protected static string|\UnitEnum|null $navigationGroup = 'الحجوزات والعملاء';
    protected static ?string $modelLabel = 'حجز';
    protected static ?string $pluralModelLabel = 'الحجوزات';
    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'flight']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('بيانات الحجز')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('booking_ref')
                        ->label('رقم الحجز')
                        ->default(fn () => 'SAA-' . date('Y') . '-' . str_pad(Booking::count() + 1, 5, '0', STR_PAD_LEFT))
                        ->disabled()
                        ->dehydrated(),

                    Forms\Components\Select::make('customer_id')
                        ->label('العميل')
                        ->relationship('customer', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\Select::make('flight_id')
                        ->label('الرحلة')
                        ->relationship('flight', 'flight_number')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Forms\Components\TextInput::make('passengers_count')
                        ->label('عدد الركاب')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required(),

                    Forms\Components\TextInput::make('total_price')
                        ->label('إجمالي السعر (ج.م)')
                        ->numeric()
                        ->prefix('ج.م')
                        ->required(),

                    Forms\Components\TextInput::make('paid_amount')
                        ->label('المبلغ المدفوع (ج.م)')
                        ->numeric()
                        ->prefix('ج.م')
                        ->default(0),

                    Forms\Components\Select::make('payment_method')
                        ->label('طريقة الدفع')
                        ->options([
                            'cash'          => 'نقدي',
                            'credit_card'   => 'بطاقة ائتمان',
                            'bank_transfer' => 'تحويل بنكي',
                            'instapay'      => 'إنستاباي',
                            'vodafone_cash' => 'فودافون كاش',
                        ]),

                    Forms\Components\Select::make('payment_status')
                        ->label('حالة الدفع')
                        ->options([
                            'pending'  => 'معلقة',
                            'partial'  => 'مدفوعة جزئياً',
                            'paid'     => 'مدفوعة بالكامل',
                            'refunded' => 'مُستردة',
                        ])
                        ->default('pending'),

                    Forms\Components\Select::make('booking_status')
                        ->label('حالة الحجز')
                        ->options([
                            'pending'    => 'قيد الانتظار',
                            'confirmed'  => 'مؤكد',
                            'checked_in' => 'تم تسجيل الوصول',
                            'cancelled'  => 'ملغي',
                            'no_show'    => 'لم يحضر',
                        ])
                        ->default('pending'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_ref')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('flight.flight_number')
                    ->label('رقم الرحلة'),

                Tables\Columns\TextColumn::make('total_price')
                    ->label('الإجمالي')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('الدفع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'pending'  => 'معلقة',
                        'partial'  => 'جزئي',
                        'paid'     => 'مدفوع',
                        'refunded' => 'مُسترد',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'pending'  => 'warning',
                        'partial'  => 'info',
                        'paid'     => 'success',
                        'refunded' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('booking_status')
                    ->label('الحجز')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'pending'    => 'قيد الانتظار',
                        'confirmed'  => 'مؤكد',
                        'checked_in' => 'تسجيل الوصول',
                        'cancelled'  => 'ملغي',
                        'no_show'    => 'لم يحضر',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'pending'    => 'gray',
                        'confirmed'  => 'success',
                        'checked_in' => 'primary',
                        'cancelled'  => 'danger',
                        'no_show'    => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الحجز')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('booking_status')
                    ->label('حالة الحجز')
                    ->options([
                        'pending'   => 'قيد الانتظار',
                        'confirmed' => 'مؤكد',
                        'cancelled' => 'ملغي',
                    ]),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('حالة الدفع')
                    ->options([
                        'pending' => 'معلقة',
                        'paid'    => 'مدفوع',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make()->label('عرض'),
                \Filament\Actions\EditAction::make()->label('تعديل'),
                \Filament\Actions\Action::make('confirm')
                    ->label('تأكيد الحجز')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->booking_status === 'pending')
                    ->action(fn ($record) => $record->update(['booking_status' => 'confirmed', 'confirmed_at' => now()])),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'view'   => Pages\ViewBooking::route('/{record}'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
