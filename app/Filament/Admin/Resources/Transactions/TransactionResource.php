<?php

namespace App\Filament\Admin\Resources\Transactions;

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Transaction;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?string $navigationLabel = 'دفتر العمليات';
    protected static ?string $pluralLabel = 'العمليات المالية';
    protected static ?string $modelLabel = 'عملية مالية';

    protected static ?string $recordTitleAttribute = 'id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['fromAccount', 'toAccount', 'createdBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('نوع العملية')
                    ->options(TransactionType::class)
                    ->required(),

                Select::make('module')
                    ->label('الموديول')
                    ->options(TransactionModule::class)
                    ->required(),

                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->prefix('ج.م')
                    ->step(0.01)
                    ->required(),

                Select::make('from_account_id')
                    ->label('من حساب')
                    ->relationship('fromAccount', 'name')
                    ->searchable()
                    ->required(),

                Select::make('to_account_id')
                    ->label('إلى حساب')
                    ->relationship('toAccount', 'name')
                    ->searchable()
                    ->required(),

                TextInput::make('notes')
                    ->label('ملاحظات')
                    ->maxLength(500),

                DatePicker::make('created_at')
                    ->label('تاريخ العملية')
                    ->disabled()
                    ->default(now()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                BadgeColumn::make('type', 'النوع')
                    ->colors([
                        'income' => 'success',
                        'expense' => 'danger',
                        'transfer' => 'info',
                    ])
                    ->formatStateUsing(fn ($state) => match($state) {
                        'income' => 'دخل',
                        'expense' => 'مصروف',
                        'transfer' => 'تحويل',
                        default => $state,
                    }),

                TextColumn::make('module', 'الموديول')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'flight' => 'طيران',
                        'bus' => 'باص',
                        'service' => 'خدمات',
                        'online' => 'إلكتروني',
                        'hajj_umra' => 'حج وعمرة',
                        'visa' => 'تأشيرات',
                        'finance' => 'مالية',
                        default => $state,
                    }),

                TextColumn::make('amount', 'المبلغ')
                    ->money('egp')
                    ->sortable()
                    ->color(fn ($record) => $record->type === 'expense' ? 'danger' : 'success'),

                TextColumn::make('fromAccount.name', 'من حساب')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('toAccount.name', 'إلى حساب')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('createdBy.name', 'بواسطة')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type', 'نوع العملية')
                    ->options(TransactionType::class),

                SelectFilter::make('module', 'الموديول')
                    ->options(TransactionModule::class),

                SelectFilter::make('from_account_id', 'من حساب')
                    ->relationship('fromAccount', 'name')
                    ->searchable(),

                SelectFilter::make('to_account_id', 'إلى حساب')
                    ->relationship('toAccount', 'name')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                \Filament\Tables\Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \Filament\Tables\Actions\ExportBulkAction::make(),
                ]),
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\FinancialStatsWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTransactions::route('/'),
            'trial-balance' => Pages\TrialBalance::route('/trial-balance'),
            'profit-loss' => Pages\ProfitLossReport::route('/profit-loss'),
            'statement' => Pages\AccountStatement::route('/statement/{accountId?}'),
        ];
    }
}
