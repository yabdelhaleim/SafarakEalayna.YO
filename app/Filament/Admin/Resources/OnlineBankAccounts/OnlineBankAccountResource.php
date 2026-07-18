<?php

namespace App\Filament\Admin\Resources\OnlineBankAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\OnlineBankAccounts\Pages\CreateOnlineBankAccount;
use App\Filament\Admin\Resources\OnlineBankAccounts\Pages\EditOnlineBankAccount;
use App\Filament\Admin\Resources\OnlineBankAccounts\Pages\ListOnlineBankAccounts;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OnlineBankAccountResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = Account::class;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'حسابات البنوك والبريد';

    protected static ?string $pluralLabel = 'حسابات البنوك والبريد (أونلاين)';

    protected static ?string $modelLabel = 'حساب بنكي / بريد';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Bank->value)
            ->where('module_type', 'online');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Bank, defaultModule: 'online');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnlineBankAccounts::route('/'),
            'create' => CreateOnlineBankAccount::route('/create'),
            'edit' => EditOnlineBankAccount::route('/{record}/edit'),
        ];
    }
}

