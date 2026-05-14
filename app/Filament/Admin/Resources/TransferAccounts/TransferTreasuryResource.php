<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class TransferTreasuryResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'المحافظ والتحويلات';

    protected static ?string $navigationLabel = 'الخزينة العامة';

    protected static ?string $pluralLabel = 'الخزينة العامة';

    protected static ?string $modelLabel = 'خزينة عامة';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'wallet_transfer')
            ->where('type', AccountType::Treasury);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Treasury, 'wallet_transfer');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTransferTreasuries::route('/'),
        ];
    }
}
