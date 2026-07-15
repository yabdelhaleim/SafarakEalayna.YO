<?php

namespace App\Filament\Resources\Finance;

use App\Enums\AccountType;
use App\Filament\Clusters\FinanceCluster;
use App\Filament\Resources\Finance\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $cluster = FinanceCluster::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $modelLabel = 'حساب / خزينة';

    protected static ?string $pluralModelLabel = 'دليل الحسابات (شجرة الحسابات)';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Section::make('البيانات الأساسية للحساب')
                    ->description('تحديد نوع الحساب والرصيد الافتتاحي')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('name')
                                ->label('اسم الحساب')
                                ->required()
                                ->maxLength(255)
                                ->helperText('مثال: البنك الأهلي، مصروفات تشغيل، إلخ.'),

                            Forms\Components\Select::make('type')
                                ->label('طبيعة الحساب (النوع)')
                                ->options(function () {
                                    $options = [];
                                    foreach (AccountType::cases() as $case) {
                                        $options[$case->value] = $case->label();
                                    }
                                    return $options;
                                })
                                ->required(),

                            Forms\Components\TextInput::make('currency')
                                ->label('العملة')
                                ->required()
                                ->maxLength(3)
                                ->default('EGP')
                                ->extraInputAttributes(['style' => 'text-transform: uppercase']),

                            Forms\Components\Toggle::make('is_active')
                                ->label('حساب نشط')
                                ->default(true)
                                ->inline(false),
                                
                            Forms\Components\Textarea::make('notes')
                                ->label('ملاحظات إضافية')
                                ->columnSpanFull(),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الحساب')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('طبيعة الحساب')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AccountType::tryFrom($state)?->label() ?? $state)
                    ->color(fn ($state) => match ($state) {
                        AccountType::Cashbox->value => 'success',
                        AccountType::Wallet->value => 'info',
                        AccountType::Expense->value => 'danger',
                        AccountType::Revenue->value => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('currency')
                    ->label('العملة')
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance')
                    ->label('الرصيد الفعلي (Ledger)')
                    ->numeric(2)
                    ->sortable()
                    ->color(fn (Account $record): string => $record->balance < 0 ? 'danger' : 'success'),

                // Phase 4 STEP 2: columns transferred from the per-module treasury
                // pages before they were removed (see git log for "feat(filament):
                // consolidate per-module treasury pages"). Kept here so the
                // general page shows the same information previously visible
                // on the per-module pages.
                TextColumn::make('fawry_transactions_count')
                    ->counts('fawryTransactions')
                    ->label('معاملات فوري')
                    ->sortable()
                    ->badge()
                    ->color('primary')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->toggleable(),

                IconColumn::make('is_module_vault')
                    ->label('خزنة الموديول الرسمية')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع الحساب')
                    ->options(function () {
                        $options = [];
                        foreach (AccountType::cases() as $case) {
                            $options[$case->value] = $case->label();
                        }
                        return $options;
                    }),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('ledger')
                    ->label('كشف حساب (القيود)')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('warning')
                    ->url(fn (Account $record) => '#') // To be implemented
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
