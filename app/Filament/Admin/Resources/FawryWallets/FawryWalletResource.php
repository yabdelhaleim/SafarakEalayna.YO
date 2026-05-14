<?php

namespace App\Filament\Admin\Resources\FawryWallets;

use BackedEnum;
use UnitEnum;
use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class FawryWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'محافظ فوري';

    protected static ?string $pluralLabel = 'محافظ فوري';

    protected static ?string $modelLabel = 'محفظة فوري';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'fawry')
            ->where('type', AccountType::Wallet);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Wallet, 'fawry');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryWallets::route('/'),
            'create' => Pages\CreateFawryWallet::route('/create'),
            'edit' => Pages\EditFawryWallet::route('/{record}/edit'),
        ];
    }
}
