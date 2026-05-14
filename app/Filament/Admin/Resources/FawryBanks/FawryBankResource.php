<?php

namespace App\Filament\Admin\Resources\FawryBanks;

use BackedEnum;
use UnitEnum;
use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class FawryBankResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'بنوك وبريد فوري';

    protected static ?string $pluralLabel = 'بنوك وبريد فوري';

    protected static ?string $modelLabel = 'حساب بنك فوري';

    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'fawry')
            ->where('type', AccountType::Bank);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Bank, 'fawry');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryBanks::route('/'),
            'create' => Pages\CreateFawryBank::route('/create'),
            'edit' => Pages\EditFawryBank::route('/{record}/edit'),
        ];
    }
}
