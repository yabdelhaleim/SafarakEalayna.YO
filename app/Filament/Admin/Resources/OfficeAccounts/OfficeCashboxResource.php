<?php

namespace App\Filament\Admin\Resources\OfficeAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OfficeCashboxResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية للمكتب';

    protected static ?string $navigationLabel = 'خزائن المكتب النقدية';

    protected static ?string $pluralLabel = 'خزائن قسم المكتب';

    protected static ?string $modelLabel = 'خزنة نقدية لقسم المكتب';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'office')
            ->where('type', AccountType::Cashbox);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Cashbox, 'office', lockModuleType: true);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOfficeCashboxes::route('/'),
        ];
    }
}
