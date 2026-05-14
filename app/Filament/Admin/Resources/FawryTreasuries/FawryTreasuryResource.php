<?php

namespace App\Filament\Admin\Resources\FawryTreasuries;

use BackedEnum;
use UnitEnum;
use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class FawryTreasuryResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'خزائن عامة فوري';

    protected static ?string $pluralLabel = 'خزائن عامة فوري';

    protected static ?string $modelLabel = 'خزينة عامة فوري';

    protected static ?int $navigationSort = 13;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'fawry')
            ->where('type', AccountType::Treasury);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Treasury, 'fawry');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryTreasuries::route('/'),
            'create' => Pages\CreateFawryTreasury::route('/create'),
            'edit' => Pages\EditFawryTreasury::route('/{record}/edit'),
        ];
    }
}
