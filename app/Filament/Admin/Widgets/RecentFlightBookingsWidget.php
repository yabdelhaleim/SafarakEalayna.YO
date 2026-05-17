<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Flight\FlightBooking;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;

class RecentFlightBookingsWidget extends BaseWidget
{
    protected static ?string $heading = 'أحدث حجوزات الطيران';

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                FlightBooking::query()->latest()->limit(5)
            )
            ->columns([
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->searchable(),
                TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('selling_price')
                    ->label('سعر البيع')
                    ->money('egp'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : (string) $state) {
                        'CONFIRMED', 'confirmed' => 'success',
                        'PENDING', 'pending' => 'warning',
                        'CANCELLED', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('عرض')
                    ->icon('heroicon-o-eye')
                    ->url(fn (FlightBooking $record): string => "/admin/flight-bookings/{$record->id}"),
            ]);
    }
}
