<?php

namespace App\Filament\Admin\Resources\HajjUmraWallets;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToHajjUmraModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HajjUmraWalletResource extends Resource
{
    use BelongsToHajjUmraModuleNavigation;

    protected static ?string $model = Account::class;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'محافظ الحج والعمرة';

    protected static ?string $pluralLabel = 'محافظ الحج والعمرة';

    protected static ?string $modelLabel = 'محفظة';

    protected static ?int $navigationSort = 21;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Wallet->value)
            ->where('module_type', 'hajj_umra');
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, 'hajj_umra');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHajjUmraWallets::route('/'),
            'create' => Pages\CreateHajjUmraWallet::route('/create'),
            'edit' => Pages\EditHajjUmraWallet::route('/{record}/edit'),
        ];
    }
}
