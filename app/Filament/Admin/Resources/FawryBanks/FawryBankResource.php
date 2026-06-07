<?php

namespace App\Filament\Admin\Resources\FawryBanks;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FawryBankResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'بنوك وبريد فوري';

    protected static ?string $pluralLabel = 'بنوك وبريد فوري';

    protected static ?string $modelLabel = 'حساب بنك فوري';

    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('type', [AccountType::Bank, AccountType::Post])
            ->where(function (Builder $query): void {
                $query->where('module_type', 'fawry')
                    ->orWhere('module', 'fawry');
            })
            ->withCount('fawryTransactions');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Bank, 'fawry', lockModuleType: true);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false)
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الحساب')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Account $record): string => 'رقم الحساب: '.$record->id),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AccountType ? $state->label() : (AccountType::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('balance')
                    ->label('الرصيد')
                    ->money(fn (Account $record): string => strtolower($record->currency ?? 'egp'))
                    ->sortable()
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('currency')
                    ->label('العملة')
                    ->badge()
                    ->color('info'),
                TextColumn::make('fawry_transactions_count')
                    ->label('معاملات فوري')
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-o-clipboard-document-list'),
                \Filament\Tables\Columns\IconColumn::make('is_module_vault')
                    ->label('خزنة قسم')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state): string => $state ? 'نشط' : 'غير نشط')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('لا توجد حسابات بنكية لفوري')
            ->emptyStateDescription('أضف حساب بنك أو بريد لتحصيل معاملات فوري.')
            ->emptyStateIcon('heroicon-o-building-library');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryBanks::route('/'),
            'create' => Pages\CreateFawryBank::route('/create'),
            'edit' => Pages\EditFawryBank::route('/{record}/edit'),
        ];
    }
}
