<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class TransferBankResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'المحافظ والتحويلات';

    protected static ?string $navigationLabel = 'بنوك وبريد المكتب';

    protected static ?string $pluralLabel = 'بنوك وبريد المكتب';

    protected static ?string $modelLabel = 'حساب بنكي/بريدي للمكتب';

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'office')
            ->where('type', AccountType::Bank);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Bank, 'office');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTransferBanks::route('/'),
        ];
    }
}
