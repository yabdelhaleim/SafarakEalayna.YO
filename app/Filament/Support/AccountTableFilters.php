<?php

namespace App\Filament\Support;

use Filament\Tables\Filters\SelectFilter;

class AccountTableFilters
{
    /**
     * @return array<int, SelectFilter>
     */
    public static function defaults(): array
    {
        return [
            SelectFilter::make('is_active')
                ->label('الحالة')
                ->options([
                    true => 'نشط',
                    false => 'غير نشط',
                ]),
            SelectFilter::make('currency')
                ->label('العملة')
                ->options([
                    'EGP' => 'جنيه مصري',
                    'SAR' => 'ريال سعودي',
                    'USD' => 'دولار أمريكي',
                    'AED' => 'درهم إماراتي',
                    'KWD' => 'دينار كويتي',
                ]),
        ];
    }
}
