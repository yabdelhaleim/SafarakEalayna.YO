<?php

namespace App\Filament\Widgets;

use App\Models\Flight;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopDestinationsWidget extends BaseWidget
{
    protected static ?int $sort = 4;
    protected static ?string $heading = 'الرحلات النشطة وتوفر المقاعد';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Flight::query()->where('available_seats', '>', 0)->latest()->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('flight_number')
                    ->label('رقم الرحلة')
                    ->weight('bold')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('airline')
                    ->label('الشركة'),
                Tables\Columns\TextColumn::make('destination')
                    ->label('الوجهة')
                    ->formatStateUsing(fn ($state) => match($state) {
                        'CAI' => 'القاهرة 🇪🇬',
                        'DXB' => 'دبي 🇦🇪',
                        'IST' => 'إسطنبول 🇹🇷',
                        'LHR' => 'لندن 🇬🇧',
                        'RUH' => 'الرياض 🇸🇦',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('available_seats')
                    ->label('المقاعد المتاحة')
                    ->badge()
                    ->color(fn ($state) => $state > 10 ? 'success' : 'warning'),
            ]);
    }
}
