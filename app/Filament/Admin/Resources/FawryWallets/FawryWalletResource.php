<?php

namespace App\Filament\Admin\Resources\FawryWallets;

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

class FawryWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'فوري';

    protected static ?string $navigationLabel = 'محافظ فوري';

    protected static ?string $pluralLabel = 'محافظ فوري';

    protected static ?string $modelLabel = 'محفظة فوري';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Wallet)
            ->where(function (Builder $query): void {
                $query->whereIn('module_type', ['fawry', 'office'])
                    ->orWhere('module', 'fawry');
            })
            ->withCount('fawryTransactions');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, 'fawry', lockModuleType: true);
    }

    public static function table(Table $table): Table
    {
        return AccountFormSchema::configureTable($table, showTypeColumn: false, showWalletDetails: true)
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المحفظة')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Account $record): string => 'رقم الحساب: '.$record->id),
                TextColumn::make('wallet_provider')
                    ->label('نوع المحفظة')
                    ->formatStateUsing(fn (Account $record): string => $record->walletProviderLabel() ?: '—')
                    ->searchable(),
                TextColumn::make('wallet_number')
                    ->label('رقم المحفظة')
                    ->searchable()
                    ->placeholder('—'),
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
            ->emptyStateHeading('لا توجد محافظ فوري')
            ->emptyStateDescription('أضف محفظة (فودافون كاش، إنستاباي…) لتحصيل معاملات فوري.')
            ->emptyStateIcon('heroicon-o-wallet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryWallets::route('/'),
            'create' => Pages\CreateFawryWallet::route('/create'),
            'edit' => Pages\EditFawryWallet::route('/{record}/edit'),
        ];
    }
}
