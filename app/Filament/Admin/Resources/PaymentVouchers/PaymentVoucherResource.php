<?php

namespace App\Filament\Admin\Resources\PaymentVouchers;

use App\Filament\Admin\Resources\PaymentVouchers\Pages\ManagePaymentVouchers;
use App\Models\PaymentVoucher;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Account;
use App\Enums\TransactionModule;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Icons\Heroicon;

class PaymentVoucherResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-minus';

    protected static string|\UnitEnum|null $navigationGroup = 'الحسابات والمالية';

    protected static ?string $navigationLabel = 'سندات الصرف';
    protected static ?string $pluralLabel = 'سندات الصرف';
    protected static ?string $modelLabel = 'سند صرف';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', TransactionType::Expense->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->label('المبلغ المصروف')
                    ->numeric()
                    ->prefix('ج.م')
                    ->required(),
                
                Select::make('from_account_id')
                    ->label('صرف من حساب (الخزينة/البنك)')
                    ->options(Account::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('to_account_id')
                    ->label('صرف لحساب (اختياري)')
                    ->options(Account::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('اتركه فارغاً إذا كان مصروفاً عاماً'),

                Textarea::make('notes')
                    ->label('البيان / ملاحظات')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id', 'رقم السند')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('fromAccount.name', 'حساب الصرف')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('amount', 'المبلغ')
                    ->money('egp')
                    ->sortable(),
                TextColumn::make('notes', 'البيان')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('from_account_id')
                    ->label('حساب الصرف')
                    ->options(Account::pluck('name', 'id')),
            ])
            ->actions([
                \Filament\Tables\Actions\ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePaymentVouchers::route('/'),
        ];
    }
}
