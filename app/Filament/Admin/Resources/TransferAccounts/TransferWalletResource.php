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

    protected static ?string $navigationLabel = 'كل المحافظ الإلكترونية';

    protected static ?string $pluralLabel = 'كل المحافظ الإلكترونية';

    protected static ?string $modelLabel = 'محفظة إلكترونية';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Wallet)
            ->where(function (Builder $q): void {
                $q->where('module_type', 'wallet_transfer')
                  ->orWhere('module', 'wallet_transfer');
            });
    }

    public static function form(Schema $schema): Schema
    {
        // 'office' هو الـ division المطلوب للـ liquidity (الـ saving hook يفرضه)
        // لكن 'wallet_transfer' في عمود `module` للتمييز بين محافظ موديول المحافظ
        // ومحافظ المكتب العامة في صفحة الإدارة.
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure(
            $schema,
            AccountType::Wallet,
            'wallet_transfer',
            lockModuleType: false
        );
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
