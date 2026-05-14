<?php

namespace App\Filament\Admin\Resources\BusTreasuries;

use BackedEnum;
use UnitEnum;
use App\Enums\AccountType;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusTreasuryResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'الخزائن النقدية (باص)';

    protected static ?string $pluralLabel = 'الخزائن النقدية (باص)';

    protected static ?string $modelLabel = 'خزينة باص';

    protected static ?int $navigationSort = 12;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'bus')
            ->where('type', AccountType::Cashbox);
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Cashbox, 'bus');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusTreasuries::route('/'),
            'create' => Pages\CreateBusTreasury::route('/create'),
            'edit' => Pages\EditBusTreasury::route('/{record}/edit'),
        ];
    }
}
