<?php

namespace App\Filament\Admin\Resources\ExchangeRates;

use App\Models\ExchangeRate;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup as ActionsBulkActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction; // غالباً محتاجها كمان
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;
    protected static bool $shouldRegisterNavigation = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $navigationLabel = 'أسعار الصرف';
    protected static ?string $pluralLabel = 'أسعار الصرف';
    protected static ?string $modelLabel = 'سعر صرف';

    protected static ?string $recordTitleAttribute = 'from_currency';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('from_currency')
                    ->label('من عملة')
                    ->maxLength(10)
                    ->required(),

                TextInput::make('to_currency')
                    ->label('إلى عملة')
                    ->maxLength(10)
                    ->required(),

                TextInput::make('rate')
                    ->label('السعر')
                    ->numeric()
                    ->step(0.0001)
                    ->required(),

                DatePicker::make('effective_date')
                    ->label('تاريخ السريان')
                    ->required(),

                Select::make('is_active')
                    ->label('حالة السعر')
                    ->options([
                        true => 'نشط',
                        false => 'غير نشط',
                    ])
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('from_currency')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('from_currency', 'من عملة')
                    ->badge()
                    ->color('info'),

                TextColumn::make('to_currency', 'إلى عملة')
                    ->badge()
                    ->color('success'),

                TextColumn::make('rate', 'السعر')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('effective_date', 'تاريخ السريان')
                    ->date('d/m/Y')
                    ->sortable(),

                BadgeColumn::make('is_active', 'الحالة')
                    ->colors([
                        true => 'success',
                        false => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'نشط' : 'غير نشط'),

                TextColumn::make('created_at', 'تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active', 'الحالة')
                    ->options([
                        true => 'نشط',
                        false => 'غير نشط',
                    ]),

                SelectFilter::make('from_currency', 'من عملة')
                    ->searchable(),
            ])
            ->defaultSort('effective_date', 'desc')
            ->recordActions([
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                ActionsBulkActionGroup::make([
                    \Filament\Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageExchangeRates::route('/'),
        ];
    }
}
