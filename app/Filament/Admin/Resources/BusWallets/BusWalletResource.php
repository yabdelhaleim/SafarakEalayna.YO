<?php

namespace App\Filament\Admin\Resources\BusWallets;

use BackedEnum;
use UnitEnum;
use App\Enums\AccountType;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'محافظ الباصات';

    protected static ?string $pluralLabel = 'محافظ الباصات';

    protected static ?string $modelLabel = 'محفظة باص';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('module_type', ['bus', 'office'])
            ->where('type', AccountType::Wallet);
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Wallet, 'bus');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusWallets::route('/'),
            'create' => Pages\CreateBusWallet::route('/create'),
            'edit' => Pages\EditBusWallet::route('/{record}/edit'),
        ];
    }
}
