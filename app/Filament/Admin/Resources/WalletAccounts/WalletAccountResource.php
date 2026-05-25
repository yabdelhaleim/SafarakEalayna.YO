<?php

namespace App\Filament\Admin\Resources\WalletAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToWalletModuleNavigation;
use App\Filament\Admin\Support\WalletModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\WalletAccounts\Pages\CreateWalletAccount;
use App\Filament\Admin\Resources\WalletAccounts\Pages\EditWalletAccount;
use App\Filament\Admin\Resources\WalletAccounts\Pages\ListWalletAccounts;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletAccountResource extends Resource
{
    use BelongsToWalletModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'كل المحافظ الإلكترونية';

    protected static ?string $pluralModelLabel = 'حسابات المحافظ الإلكترونية';

    protected static ?string $modelLabel = 'حساب محفظة';

    /** بعد «أنواع المحافظ» وقبل «عمليات المحافظ». */
    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Wallet->value);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletAccounts::route('/'),
            'create' => CreateWalletAccount::route('/create'),
            'edit' => EditWalletAccount::route('/{record}/edit'),
        ];
    }
}
