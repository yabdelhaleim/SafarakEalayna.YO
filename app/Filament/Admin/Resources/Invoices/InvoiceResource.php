<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Models\Invoice;
use App\Filament\Admin\Resources\Invoices\Pages;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'الفواتير';

    protected static ?string $pluralLabel = 'الفواتير';

    protected static ?string $modelLabel = 'فاتورة';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الفاتورة')
                    ->schema([
                        TextInput::make('invoice_number')
                            ->label('رقم الفاتورة')
                            ->required()
                            ->unique(ignoreRecord: true),

                        Select::make('customer_id')
                            ->label('العميل')
                            ->relationship('customer', 'full_name')
                            ->searchable()
                            ->required(),

                        Select::make('status')
                            ->label('الحالة')
                            ->options([
                                'draft' => 'مسودة',
                                'sent' => 'مرسلة',
                                'paid' => 'مدفوعة',
                                'partially_paid' => 'مدفوعة جزئياً',
                                'overdue' => 'متأخرة',
                                'cancelled' => 'ملغاة',
                            ])
                            ->default('draft')
                            ->required(),

                        DatePicker::make('invoice_date')
                            ->label('تاريخ الفاتورة')
                            ->required()
                            ->default(now()),

                        DatePicker::make('due_date')
                            ->label('تاريخ الاستحقاق')
                            ->required()
                            ->default(now()->addDays(30)),
                    ])
                    ->columns(2),

                Section::make('بنود الفاتورة')
                    ->schema([
                        Repeater::make('items')
                            ->label('البنود')
                            ->relationship('items')
                            ->schema([
                                TextInput::make('description')
                                    ->label('الوصف')
                                    ->required()
                                    ->columnSpan(3),
                                TextInput::make('quantity')
                                    ->label('الكمية')
                                    ->numeric()
                                    ->default(1)
                                    ->required(),
                                TextInput::make('unit_price')
                                    ->label('سعر الوحدة')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->required(),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),

                Section::make('ملاحظات')
                    ->schema([
                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('رقم الفاتورة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),

                BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'draft' => 'gray',
                        'sent' => 'info',
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'danger',
                    ])
                    ->formatStateUsing(fn ($state): string => match($state instanceof \BackedEnum ? $state->value : (string) $state) {
                        'draft' => 'مسودة',
                        'sent' => 'مرسلة',
                        'paid' => 'مدفوعة',
                        'partially_paid' => 'مدفوعة جزئياً',
                        'overdue' => 'متأخرة',
                        'cancelled' => 'ملغاة',
                        default => $state instanceof \BackedEnum ? $state->value : (string) $state,
                    }),

                TextColumn::make('total_amount')
                    ->label('الإجمالي')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('invoice_date')
                    ->label('التاريخ')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
