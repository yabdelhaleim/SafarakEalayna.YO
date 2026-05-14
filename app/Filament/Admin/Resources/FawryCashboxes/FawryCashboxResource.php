<?php

namespace App\Filament\Admin\Resources\FawryCashboxes;

use BackedEnum;
use UnitEnum;
use App\Enums\AccountType;
use App\Models\Account;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;

class FawryCashboxResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'خزائن فوري';

    protected static ?string $pluralLabel = 'خزائن فوري';

    protected static ?string $modelLabel = 'خزينة فوري';

    protected static ?int $navigationSort = 12;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('module_type', 'fawry')
            ->where('type', AccountType::Cashbox);
    }

    public static function form(Schema $schema): Schema
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configure($schema, AccountType::Cashbox, 'fawry');
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Admin\Resources\Accounts\AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryCashboxes::route('/'),
            'create' => Pages\CreateFawryCashbox::route('/create'),
            'edit' => Pages\EditFawryCashbox::route('/{record}/edit'),
        ];
    }
}
