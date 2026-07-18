<?php

namespace App\Filament\Admin\Resources\TourismAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TourismWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية للسياحة';

    protected static ?string $navigationLabel = 'محافظ السياحة';

    protected static ?string $pluralLabel = 'محافظ السياحة';

    protected static ?string $modelLabel = 'محفظة للسياحة';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'tourism')
            ->where('type', AccountType::Wallet);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, 'tourism');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTourismWallets::route('/'),
        ];
    }
}
