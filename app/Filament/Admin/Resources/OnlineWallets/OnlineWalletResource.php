<?php

namespace App\Filament\Admin\Resources\OnlineWallets;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\OnlineWallets\Pages\CreateOnlineWallet;
use App\Filament\Admin\Resources\OnlineWallets\Pages\EditOnlineWallet;
use App\Filament\Admin\Resources\OnlineWallets\Pages\ListOnlineWallets;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OnlineWalletResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'محافظ الخدمات الأونلاين';

    protected static ?string $pluralLabel = 'محافظ الخدمات الأونلاين';

    protected static ?string $modelLabel = 'محفظة';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Wallet->value)
            ->where('module_type', 'online');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, defaultModule: 'online');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnlineWallets::route('/'),
            'create' => CreateOnlineWallet::route('/create'),
            'edit' => EditOnlineWallet::route('/{record}/edit'),
        ];
    }
}

