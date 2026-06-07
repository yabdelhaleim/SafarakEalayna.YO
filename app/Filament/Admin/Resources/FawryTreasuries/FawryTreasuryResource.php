<?php

namespace App\Filament\Admin\Resources\FawryTreasuries;

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

class FawryTreasuryResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'خزائن عامة فوري';

    protected static ?string $pluralLabel = 'خزائن عامة فوري';

    protected static ?string $modelLabel = 'خزينة عامة فوري';

    protected static ?int $navigationSort = 13;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Treasury)
            ->where(function (Builder $query): void {
                $query->where('module_type', 'fawry')
                    ->orWhere('module', 'fawry');
            })
            ->withCount('fawryTransactions');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Treasury, 'fawry', lockModuleType: true);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false)
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الخزينة العامة')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Account $record): string => 'رقم الحساب: '.$record->id),
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
                    ->label('خزنة الموديول الرسمية')
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
            ->emptyStateHeading('لا توجد خزائن عامة لفوري')
            ->emptyStateDescription('أضف خزينة عامة رسمية لموديول فوري (يمكن تفعيل «خزنة الموديول الرسمية» لاستخدامها تلقائياً).')
            ->emptyStateIcon('heroicon-o-briefcase');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryTreasuries::route('/'),
            'create' => Pages\CreateFawryTreasury::route('/create'),
            'edit' => Pages\EditFawryTreasury::route('/{record}/edit'),
        ];
    }
}
