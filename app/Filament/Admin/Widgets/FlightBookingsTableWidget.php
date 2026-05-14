<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\FlightBookingStatus;
use App\Models\Flight\FlightBooking;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class FlightBookingsTableWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;
    
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'تفاصيل حجوزات الطيران الحديثة';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                FlightBooking::query()->latest('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('pnr')
                    ->label('PNR')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                    
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('selling_price')
                    ->label('الإجمالي')
                    ->money(fn ($record) => $record->currency ?? 'EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('المدفوع')
                    ->money(fn ($record) => $record->currency ?? 'EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining_amount')
                    ->label('المتبقي')
                    ->money(fn ($record) => $record->currency ?? 'EGP')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge(),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الحجز')
                    ->dateTime('d/m/Y g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                \Filament\Actions\Action::make('view')
                    ->label('عرض التفاصيل')
                    ->url(fn (FlightBooking $record): string => \App\Filament\Admin\Resources\FlightBookings\FlightBookingResource::getUrl('edit', ['record' => $record]))
                    ->icon('heroicon-m-eye')
            ])
            ->paginated([5, 10, 25, 50])
            ->defaultPaginationPageOption(5);
    }
}
