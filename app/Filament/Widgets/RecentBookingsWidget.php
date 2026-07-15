<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentBookingsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected static bool $isLazy = true;

    protected static ?string $heading = 'آخر الحجوزات المضافة';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::query()->latest()->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('booking_ref')
                    ->label('رقم الحجز')
                    ->weight('bold')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل'),
                Tables\Columns\TextColumn::make('total_price')
                    ->label('القيمة')
                    ->money('EGP'),
                Tables\Columns\TextColumn::make('booking_status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {                        'pending' => 'قيد الانتظار',                        'confirmed' => 'مؤكد',                        'cancelled' => 'ملغي',                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {                        'pending' => 'warning',                        'confirmed' => 'success',                        'cancelled' => 'danger',                        default => 'gray',
                    }),
            ]);
    }
}
