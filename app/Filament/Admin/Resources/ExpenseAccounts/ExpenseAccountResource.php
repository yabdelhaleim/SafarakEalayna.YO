<?php

namespace App\Filament\Admin\Resources\ExpenseAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\ExpenseAccounts\Pages\CreateExpenseAccount;
use App\Filament\Admin\Resources\ExpenseAccounts\Pages\EditExpenseAccount;
use App\Filament\Admin\Resources\ExpenseAccounts\Pages\ListExpenseAccounts;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpenseAccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'expense-accounts';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $navigationLabel = 'بنود المصروفات';

    protected static ?string $pluralLabel = 'بنود المصروفات';

    protected static ?string $modelLabel = 'بند مصروف';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Expense->value);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Expense, 'general');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenseAccounts::route('/'),
            'create' => CreateExpenseAccount::route('/create'),
            'edit' => EditExpenseAccount::route('/{record}/edit'),
        ];
    }
}
