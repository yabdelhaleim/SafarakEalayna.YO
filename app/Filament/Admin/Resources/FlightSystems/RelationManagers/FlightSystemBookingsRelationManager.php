<?php

namespace App\Filament\Admin\Resources\FlightSystems\RelationManagers;

use App\Enums\FlightBookingStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlightSystemBookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['customer:id,full_name']))
            ->recordTitleAttribute('booking_number')
            ->columns([
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        if ($state instanceof FlightBookingStatus) {
                            return $state->label();
                        }

                        return FlightBookingStatus::tryFrom((string) $state)?->label() ?? (string) $state;
                    }),
                TextColumn::make('purchase_price')
                    ->label('شراء')
                    ->money(fn ($record): string => strtolower((string) ($record->currency ?? 'egp')))
                    ->sortable(),
                TextColumn::make('selling_price')
                    ->label('بيع')
                    ->money(fn ($record): string => strtolower((string) ($record->currency ?? 'egp')))
                    ->sortable(),
                TextColumn::make('departure_date')
                    ->label('المغادرة')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
