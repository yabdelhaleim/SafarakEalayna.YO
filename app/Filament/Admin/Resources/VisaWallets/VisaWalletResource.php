<?php

namespace App\Filament\Admin\Resources\VisaWallets;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Filament\Support\AccountTableFilters;
use App\Models\Account;
use App\Models\VisaBooking;
use BackedEnum;
use UnitEnum;
use Filament\Tables\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VisaWalletResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-wallet';

    protected static string|UnitEnum|null $navigationGroup = 'التأشيرات';

    protected static ?string $navigationLabel = 'محافظ التأشيرات';

    protected static ?string $pluralLabel = 'محافظ التأشيرات';

    protected static ?string $modelLabel = 'محفظة';

    protected static ?int $navigationSort = 31;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Wallet->value)
            ->where('module_type', 'visas');
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Wallet, 'visas');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['visaBookings' => function ($query) {
                $query->where('status', '!=', 'cancelled');
            }]))
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المحفظة')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Model $record): string => 'رقم الحساب: ' . $record->id)
                    ->grow(true),
                TextColumn::make('balance')
                    ->label('الرصيد')
                    ->money('egp')
                    ->sortable()
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger')
                    ->description(fn (Model $record): ?string => $record->currency ?? 'EGP'),
                TextColumn::make('visa_bookings_count')
                    ->label('عدد المعاملات')
                    ->counts('visaBookings')
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-o-clipboard-document-list'),
                TextColumn::make('is_active')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state): string => $state ? 'نشط' : 'غير نشط')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'danger'),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters(AccountTableFilters::defaults())
            ->recordActions([
                Action::make('statement')
                    ->label('كشف الحساب')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn (Model $record): string => \App\Filament\Admin\Pages\AccountStatement::getUrl(['accountId' => $record->id])),
            ])
            ->emptyStateHeading('لا توجد محافظ')
            ->emptyStateDescription('ابدأ بإضافة محفظة جديدة لموديول التأشيرات')
            ->emptyStateIcon('heroicon-o-wallet');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVisaWallets::route('/'),
            'create' => Pages\CreateVisaWallet::route('/create'),
            'edit' => Pages\EditVisaWallet::route('/{record}/edit'),
        ];
    }
}

// Add relationship to Account model
if (!method_exists(Account::class, 'visaBookings')) {
    Account::resolveRelationUsing('visaBookings', function (Account $model) {
        return $model->hasMany(VisaBooking::class, 'account_id');
    });
}
