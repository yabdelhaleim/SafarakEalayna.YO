<?php

namespace App\Filament\Admin\Resources\ReceiptVouchers;

use App\Filament\Admin\Resources\ReceiptVouchers\Pages\ManageReceiptVouchers;
use App\Models\ReceiptVoucher;
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

class ReceiptVoucherResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'الحسابات والمالية';

    protected static ?string $navigationLabel = 'سندات القبض';
    protected static ?string $pluralLabel = 'سندات القبض';
    protected static ?string $modelLabel = 'سند قبض';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', TransactionType::Income->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->label('المبلغ المحصل')
                    ->numeric()
                    ->prefix('ج.م')
                    ->required(),
                
                Select::make('to_account_id')
                    ->label('إيداع في حساب (الخزينة/البنك)')
                    ->options(Account::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('from_account_id')
                    ->label('تحصيل من حساب (اختياري)')
                    ->options(Account::where('is_active', true)->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('اتركه فارغاً إذا كان التحصيل إيراد عام'),

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
                TextColumn::make('toAccount.name', 'حساب الإيداع')
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
                SelectFilter::make('to_account_id')
                    ->label('حساب الإيداع')
                    ->options(Account::pluck('name', 'id')),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReceiptVouchers::route('/'),
        ];
    }
}
