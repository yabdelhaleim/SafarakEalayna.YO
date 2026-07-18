<?php

namespace App\Filament\Admin\Resources\FlightWallets;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FlightWalletResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = Account::class;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'محافظ الطيران';

    protected static ?string $pluralLabel = 'محافظ الطيران';

    protected static ?string $modelLabel = 'محفظة طيران';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'flights')
            ->where('type', AccountType::Wallet);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, 'flights');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightWallets::route('/'),
            'create' => Pages\CreateFlightWallet::route('/create'),
            'edit' => Pages\EditFlightWallet::route('/{record}/edit'),
        ];
    }
}
