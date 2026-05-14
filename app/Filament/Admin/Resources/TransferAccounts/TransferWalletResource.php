<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class TransferWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'المحافظ والتحويلات';

    protected static ?string $navigationLabel = 'المحافظ';

    protected static ?string $pluralLabel = 'المحافظ';

    protected static ?string $modelLabel = 'محفظة';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'wallet_transfer')
            ->where('type', AccountType::Wallet);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Wallet, 'wallet_transfer');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTransferWallets::route('/'),
        ];
    }
}
