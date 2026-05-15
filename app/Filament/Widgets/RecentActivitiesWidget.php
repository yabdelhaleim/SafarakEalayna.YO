<?php

namespace App\Filament\Widgets;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentActivitiesWidget extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'آخر النشاطات';

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected static ?string $pollingInterval = '60s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                \App\Models\Flight\FlightBooking::query()
                    ->with(['customer'])
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('id')
                    ->label('الرقم')
                    ->sortable()
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->customer?->email ?? '')
                    ->weight('medium'),

                BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'danger' => 'cancelled',
                        'warning' => 'pending',
                        'success' => 'confirmed',
                        'info' => 'processing',
                    ])
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('total_price')
                    ->label('السعر')
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
            ->recordUrl(fn ($record) => route('filament.admin.resources.flight-bookings.edit', $record))
            ->emptyStateHeading('لا توجد نشاطات حديثة')
            ->emptyStateDescription('ابدأ بإضافة حجز جديد لرؤيته هنا')
            ->emptyStateIcon('heroicon-o-inbox');
    }
}
