<?php

namespace App\Filament\Admin\Resources\OfficeAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfficeWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية للمكتب';

    protected static ?string $navigationLabel = 'محافظ المكتب';

    protected static ?string $pluralLabel = 'محافظ قسم المكتب';

    protected static ?string $modelLabel = 'محفظة قسم المكتب';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'office')
            ->where('type', AccountType::Wallet);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, 'office', lockModuleType: true);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOfficeWallets::route('/'),
        ];
    }
}
