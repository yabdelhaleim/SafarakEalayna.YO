<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class TransferCashboxResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'المحافظ والتحويلات';

    protected static ?string $navigationLabel = 'الخزائن النقدية';

    protected static ?string $pluralLabel = 'الخزائن النقدية';

    protected static ?string $modelLabel = 'خزنة نقدية';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'wallet_transfer')
            ->where('type', AccountType::Cash);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Cash, 'wallet_transfer');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTransferCashboxes::route('/'),
        ];
    }
}
