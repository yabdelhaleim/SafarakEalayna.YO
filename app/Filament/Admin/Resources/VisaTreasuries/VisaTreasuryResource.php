<?php

namespace App\Filament\Admin\Resources\VisaTreasuries;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\Accounts\AccountFormSchema;
use App\Models\Account;
use App\Models\VisaBooking;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VisaTreasuryResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'التأشيرات';

    protected static ?string $navigationLabel = 'الخزائن النقدية';

    protected static ?string $pluralLabel = 'الخزائن النقدية';

    protected static ?string $modelLabel = 'خزينة نقدية';

    protected static ?int $navigationSort = 32;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', AccountType::Cashbox->value)
            ->where('module_type', 'visas');
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return AccountFormSchema::configure($schema, AccountType::Cashbox);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['visaBookings' => function ($query) {
                $query->where('status', '!=', 'cancelled');
            }]))
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الخزينة')
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
                TextColumn::make('currency')
                    ->label('العملة')
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->toggleable(),
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
            ->recordActions([
                Action::make('statement')
                    ->label('كشف الحساب')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn (Model $record): string => \App\Filament\Admin\Resources\Transactions\Pages\AccountStatement::getUrl(['accountId' => $record->id])),
                Action::make('quickDeposit')
                    ->label('إيداع سريع')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->color('success')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('ج.م'),
                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (Model $record, array $data): void {
                        try {
                            \DB::transaction(function () use ($record, $data) {
                                \App\Models\Transaction::create([
                                    'type' => \App\Enums\TransactionType::Deposit->value,
                                    'module' => \App\Enums\TransactionModule::Visa->value,
                                    'amount' => (float) $data['amount'],
                                    'account_id' => $record->id,
                                    'date' => now(),
                                    'notes' => $data['notes'] ?? 'إيداع في الخزينة',
                                    'created_by' => auth()->id(),
                                ]);

                                $record->increment('balance', (float) $data['amount']);
                            });

                            \Filament\Notifications\Notification::make()
                                ->title('تم الإيداع بنجاح')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('فشل الإيداع')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->emptyStateHeading('لا توجد خزائن نقدية')
            ->emptyStateDescription('ابدأ بإضافة خزينة نقدية جديدة لموديول التأشيرات')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVisaTreasuries::route('/'),
            'create' => Pages\CreateVisaTreasury::route('/create'),
            'edit' => Pages\EditVisaTreasury::route('/{record}/edit'),
        ];
    }
}

// Add relationship to Account model
if (!method_exists(Account::class, 'visaBookings')) {
    Account::resolveRelationUsing('visaBookings', function (Account $model) {
        return $model->hasMany(VisaBooking::class, 'account_id');
    });
}
