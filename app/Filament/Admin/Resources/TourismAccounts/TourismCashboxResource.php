<?php

namespace App\Filament\Admin\Resources\TourismAccounts;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TourismCashboxResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية للسياحة';

    protected static ?string $navigationLabel = 'خزائن السياحة النقدية';

    protected static ?string $pluralLabel = 'خزائن السياحة النقدية';

    protected static ?string $modelLabel = 'خزنة نقدية للسياحة';

    protected static ?int $navigationSort = 30;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'tourism')
            ->where('type', AccountType::Cashbox);
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Cashbox, 'tourism');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTourismCashboxes::route('/'),
        ];
    }
}
