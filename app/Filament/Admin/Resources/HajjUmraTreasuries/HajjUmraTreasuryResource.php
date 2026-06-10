<?php

namespace App\Filament\Admin\Resources\HajjUmraTreasuries;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToHajjUmraModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HajjUmraTreasuryResource extends Resource
{
    use BelongsToHajjUmraModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'خزائن الحج والعمرة';

    protected static ?string $pluralLabel = 'خزائن الحج والعمرة';

    protected static ?string $modelLabel = 'خزينة';

    protected static ?int $navigationSort = 22;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Cashbox->value)
            ->where('module_type', 'hajj_umra');
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Cashbox, 'hajj_umra');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHajjUmraTreasuries::route('/'),
            'create' => Pages\CreateHajjUmraTreasury::route('/create'),
            'edit' => Pages\EditHajjUmraTreasury::route('/{record}/edit'),
        ];
    }
}
