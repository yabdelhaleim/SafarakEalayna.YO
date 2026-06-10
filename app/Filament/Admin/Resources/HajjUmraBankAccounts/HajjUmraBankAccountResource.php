<?php

namespace App\Filament\Admin\Resources\HajjUmraBankAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToHajjUmraModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\HajjUmraBankAccounts\Pages\CreateHajjUmraBankAccount;
use App\Filament\Admin\Resources\HajjUmraBankAccounts\Pages\EditHajjUmraBankAccount;
use App\Filament\Admin\Resources\HajjUmraBankAccounts\Pages\ListHajjUmraBankAccounts;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HajjUmraBankAccountResource extends Resource
{
    use BelongsToHajjUmraModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'حسابات البنوك والبريد';

    protected static ?string $pluralLabel = 'حسابات البنوك والبريد';

    protected static ?string $modelLabel = 'حساب بنكي / بريد';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Bank->value)
            ->where('module_type', 'hajj_umra');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Bank, 'hajj_umra');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHajjUmraBankAccounts::route('/'),
            'create' => CreateHajjUmraBankAccount::route('/create'),
            'edit' => EditHajjUmraBankAccount::route('/{record}/edit'),
        ];
    }
}

