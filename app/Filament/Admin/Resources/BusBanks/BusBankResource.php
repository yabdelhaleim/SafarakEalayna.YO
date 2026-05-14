<?php

namespace App\Filament\Admin\Resources\BusBanks;

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

class BusBankResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'حسابات البنوك والبريد (باص)';

    protected static ?string $pluralLabel = 'حسابات البنوك والبريد (باص)';

    protected static ?string $modelLabel = 'حساب بنكي';

    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'bus')
            ->where('type', AccountType::Bank);
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Bank, 'bus');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusBanks::route('/'),
            'create' => Pages\CreateBusBank::route('/create'),
            'edit' => Pages\EditBusBank::route('/{record}/edit'),
        ];
    }
}
