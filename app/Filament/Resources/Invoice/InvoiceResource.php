<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Invoice\InvoiceResource\Pages;
use App\Models\Invoice;
use BackedEnum;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Forms\Components\TextInput;
use Filament\Components\Forms\MoneyInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'الفواتير';

    protected static ?int $navigationSort = 1;

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('معلومات الفاتورة')
                    ->schema([
                        TextInput::make('invoice_number', 'رقم الفاتورة')
                            ->default(fn () => 'INV-' . now()->format('Ymd') . '-0001')
                            ->disabled()
                            ->required(),

                        Select::make('customer_id', 'العميل')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->required()
                            ->createOptionFormUsing(fn (Form $form) => $form->schema([
                                TextInput::make('name', 'اسم العميل')
                                    ->required(),
                                TextInput::make('phone', 'رقم الهاتف')
                                    ->tel()
                                    ->required(),
                            ])),

                        Select::make('type', 'نوع الفاتورة')
                            ->options([
                                'flight' => 'حجزات طيران',
                                'bus' => 'حجزات باصات',
                                'service' => 'خدمات',
                                'online' => 'خدمات أونلاين',
                                'hajj_umrah' => 'حج وعمرة',
                                'visa' => 'تأشيرات',
                                'general' => 'عام',
                            ])
                            ->default('general')
                            ->required(),

                        DatePicker::make('invoice_date', 'تاريخ الفاتورة')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->default(now()),

                        DatePicker::make('due_date', 'تاريخ الاستحقاق')
                            ->required()
                            ->displayFormat('d/m/Y')
                            ->default(now()->addDays(30)),
                    ])
                    ->columns(2),

                Section::make('بنود الفاتورة')
                    ->schema([
                        Repeater::make('items', 'البنود')
                            ->relationship('items')
                            ->schema([
                                TextInput::make('description', 'الوصف')
                                    ->required()
                                    ->columnSpan(4),

                                TextInput::make('quantity', 'الكمية')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->required()
                                    ->columnSpan(2),

                                MoneyInput::make('unit_price', 'السعر الواحد')
                                    ->required()
                                    ->columnSpan(2),

                                TextInput::make('tax_rate', 'ضريبة البيع (%)')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->suffix('%')
                                    ->columnSpan(2),

                                TextInput::make('discount_amount', 'الخصم')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->prefix('ج.م')
                                    ->columnSpan(2),

                                Textarea::make('details', 'تفاصيل إضافية')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(6)
                            ->itemLabel(fn () => 'بند')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('معلومات إضافية')
                    ->schema([
                        Textarea::make('notes', 'ملاحظات')
                            ->rows(2)
                            ->columnSpan(2),

                        Textarea::make('terms', 'شروط الدفع')
                            ->rows(2)
                            ->columnSpan(2),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('invoice_number', 'رقم الفاتورة')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('customer.name', 'العميل')
                    ->searchable()
                    ->sortable(),

                BadgeColumn::make('status', 'الحالة')
                    ->colors([
                        'draft' => 'gray',
                        'sent' => 'info',
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
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

                TextColumn::make('total_amount', 'الإجمالي')
                    ->money('jod')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('paid_amount', 'المدفوع')
                    ->money('jod')
                    ->sortable(),

                TextColumn::make('due_amount', 'المتبقي')
                    ->money('jod')
                    ->sortable()
                    ->sortable()
                    ->color('danger'),

                TextColumn::make('invoice_date', 'تاريخ الفاتورة')
                    ->date('d/m/Y')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('due_date', 'تاريخ الاستحقاق')
                    ->date('d/m/Y')
                    ->sortable()
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('status', 'الحالة')
                    ->options([
                        'draft' => 'مسودة',
                        'sent' => 'مرسلة',
                        'paid' => 'مدفوعة',
                        'partially_paid' => 'مدفوعة جزئياً',
                        'overdue' => 'متأخرة',
                        'cancelled' => 'ملغاة',
                    ]),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->actions([
                \Filament\Actions\ViewAction::make()
                    ->label('عرض'),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            'customer',
            'items',
            'payments',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/index'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
