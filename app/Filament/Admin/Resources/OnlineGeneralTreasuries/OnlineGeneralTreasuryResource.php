<?php

namespace App\Filament\Admin\Resources\OnlineGeneralTreasuries;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\OnlineGeneralTreasuries\Pages\CreateOnlineGeneralTreasury;
use App\Filament\Admin\Resources\OnlineGeneralTreasuries\Pages\EditOnlineGeneralTreasury;
use App\Filament\Admin\Resources\OnlineGeneralTreasuries\Pages\ListOnlineGeneralTreasuries;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OnlineGeneralTreasuryResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'الخزنة العامة (أونلاين)';

    protected static ?string $pluralLabel = 'الخزنة العامة (أونلاين)';

    protected static ?string $modelLabel = 'خزنة عامة';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Treasury->value)
            ->where('module_type', 'online');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Treasury, defaultModule: 'online');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnlineGeneralTreasuries::route('/'),
            'create' => CreateOnlineGeneralTreasury::route('/create'),
            'edit' => EditOnlineGeneralTreasury::route('/{record}/edit'),
        ];
    }
}

