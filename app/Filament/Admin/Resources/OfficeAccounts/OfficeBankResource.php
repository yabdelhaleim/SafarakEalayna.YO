<?php

namespace App\Filament\Admin\Resources\OfficeAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfficeBankResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية للمكتب';

    protected static ?string $navigationLabel = 'بنوك وبريد المكتب';

    protected static ?string $pluralLabel = 'بنوك قسم المكتب';

    protected static ?string $modelLabel = 'حساب بنكي/بريدي لقسم المكتب';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'office')
            ->where('type', AccountType::Bank);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Bank, 'office', lockModuleType: true);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOfficeBanks::route('/'),
        ];
    }
}
