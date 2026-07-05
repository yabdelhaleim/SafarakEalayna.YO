<?php

namespace App\Filament\Admin\Resources\FlightTreasuries;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\FlightTreasuries\Pages\CreateFlightTreasury;
use App\Filament\Admin\Resources\FlightTreasuries\Pages\EditFlightTreasury;
use App\Filament\Admin\Resources\FlightTreasuries\Pages\ListFlightTreasuries;
use App\Filament\Admin\Resources\FlightTreasuries\Schemas\FlightTreasuryForm;
use App\Filament\Admin\Resources\FlightTreasuries\Tables\FlightTreasuriesTable;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FlightTreasuryResource extends Resource
{
    use BelongsToFlightModuleNavigation;
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'الخزائن النقدية';

    protected static ?string $pluralLabel = 'الخزائن النقدية';

    protected static ?string $modelLabel = 'خزينة نقدية';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 9;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'flights')
            ->where('type', \App\Enums\AccountType::Cashbox->value)
            // استثناء حسابات الإقفال والرصيد المسبق والتسوية (حسابات داخلية)
            ->where('name', 'not like', '%إقفال%')
            ->where('name', 'not like', '%(نظام)%')
            ->where('name', 'not like', '%رصيد مسبق%')
            ->where('name', 'not like', '%تسوية%');
    }

    public static function form(Schema $schema): Schema
    {
        return FlightTreasuryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlightTreasuriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlightTreasuries::route('/'),
            'create' => CreateFlightTreasury::route('/create'),
            'edit' => EditFlightTreasury::route('/{record}/edit'),
        ];
    }
}
