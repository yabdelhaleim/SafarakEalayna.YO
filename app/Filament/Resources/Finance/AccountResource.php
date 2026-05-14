<?php

namespace App\Filament\Resources\Finance;

use App\Enums\AccountType;
use App\Filament\Clusters\FinanceCluster;
use App\Filament\Resources\Finance\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $cluster = FinanceCluster::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    
    protected static ?string $modelLabel = 'حساب / خزينة';

    protected static ?string $pluralModelLabel = 'دليل الحسابات (شجرة الحسابات)';

    public static function form(Form $form): Form
    {
        return $form
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
                        AccountType::Cashbox->value, AccountType::Treasury->value => 'success',
                        AccountType::Bank->value => 'info',
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('ledger')
                    ->label('كشف حساب (القيود)')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('warning')
                    ->url(fn (Account $record) => '#') // To be implemented
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
