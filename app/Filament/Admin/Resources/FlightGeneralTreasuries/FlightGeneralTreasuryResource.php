<?php

namespace App\Filament\Admin\Resources\FlightGeneralTreasuries;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\FlightGeneralTreasuries\Pages\CreateFlightGeneralTreasury;
use App\Filament\Admin\Resources\FlightGeneralTreasuries\Pages\EditFlightGeneralTreasury;
use App\Filament\Admin\Resources\FlightGeneralTreasuries\Pages\ListFlightGeneralTreasuries;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FlightGeneralTreasuryResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'الخزنة العامة للطيران';

    protected static ?string $pluralLabel = 'الخزنة العامة للطيران';

    protected static ?string $modelLabel = 'خزنة عامة (طيران)';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Treasury->value)
            ->where('module_type', 'flights');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Treasury, 'flights');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlightGeneralTreasuries::route('/'),
            'create' => CreateFlightGeneralTreasury::route('/create'),
            'edit' => EditFlightGeneralTreasury::route('/{record}/edit'),
        ];
    }
}

