<?php

namespace App\Filament\Admin\Resources\OnlineTreasuries;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Admin\Resources\OnlineTreasuries\Pages\CreateOnlineTreasury;
use App\Filament\Admin\Resources\OnlineTreasuries\Pages\EditOnlineTreasury;
use App\Filament\Admin\Resources\OnlineTreasuries\Pages\ListOnlineTreasuries;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OnlineTreasuryResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'خزائن النقدي';

    protected static ?string $pluralLabel = 'خزائن النقدي (أونلاين)';

    protected static ?string $modelLabel = 'خزينة';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Cashbox->value)
            ->where('module_type', 'online');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Cashbox, defaultModule: 'online');
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnlineTreasuries::route('/'),
            'create' => CreateOnlineTreasury::route('/create'),
            'edit' => EditOnlineTreasury::route('/{record}/edit'),
        ];
    }
}

