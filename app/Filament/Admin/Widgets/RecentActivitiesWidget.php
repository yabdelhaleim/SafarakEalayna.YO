<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\FlightBookings\FlightBookingResource;
use App\Models\Flight\FlightBooking;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentActivitiesWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'آخر النشاطات';

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                FlightBooking::query()
                    ->with(['customer'])
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->sortable()
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->customer?->phone ?? '')
                    ->weight('medium'),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn ($state): string => match (is_string($state) ? $state : ($state->value ?? (string) $state)) {
                        'cancelled', 'CANCELLED' => 'danger',
                        'pending', 'PENDING' => 'warning',
                        'confirmed', 'CONFIRMED', 'issued', 'ISSUED' => 'success',
                        'processing', 'PROCESSING' => 'info',
                        default => 'gray',
                    })
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('selling_price')
                    ->label('سعر البيع')
                    ->money('egp')
                    ->sortable()
                    ->weight('semibold')
                    ->color('success'),

                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->since()
                    ->description(fn ($record) => $record->created_at->format('d/m/Y H:i')),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->recordUrl(fn ($record) => FlightBookingResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('لا توجد نشاطات حديثة')
            ->emptyStateDescription('ابدأ بإضافة حجز جديد لرؤيته هنا')
            ->emptyStateIcon('heroicon-o-inbox');
    }
}
