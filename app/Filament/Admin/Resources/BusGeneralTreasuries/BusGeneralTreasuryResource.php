<?php

namespace App\Filament\Admin\Resources\BusGeneralTreasuries;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\BusGeneralTreasuries\Pages\CreateBusGeneralTreasury;
use App\Filament\Admin\Resources\BusGeneralTreasuries\Pages\EditBusGeneralTreasury;
use App\Filament\Admin\Resources\BusGeneralTreasuries\Pages\ListBusGeneralTreasuries;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BusGeneralTreasuryResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'الخزنة العامة (باص)';

    protected static ?string $pluralLabel = 'الخزنة العامة (باص)';

    protected static ?string $modelLabel = 'خزنة عامة (باص)';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Treasury->value)
            ->where('module_type', 'bus');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Treasury, 'bus');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusGeneralTreasuries::route('/'),
            'create' => CreateBusGeneralTreasury::route('/create'),
            'edit' => EditBusGeneralTreasury::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
