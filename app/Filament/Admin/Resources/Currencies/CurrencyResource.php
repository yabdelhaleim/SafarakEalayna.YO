<?php

namespace App\Filament\Admin\Resources\Currencies;

use App\Filament\Admin\Resources\Currencies\Pages\CreateCurrency;
use App\Filament\Admin\Resources\Currencies\Pages\EditCurrency;
use App\Filament\Admin\Resources\Currencies\Pages\ListCurrencies;
use App\Models\Setting\Currency;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CurrencyResource extends Resource
{
    protected static ?string $model = Currency::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'الحسابات والمالية';

    protected static ?string $navigationLabel = 'أسعار الصرف وشراء العملة';

    protected static ?string $pluralLabel = 'العملات';

    protected static ?string $modelLabel = 'عملة';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->where('is_active', true)->count();

        return (string) $count;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات العملة')
                    ->description('العملات النشطة تظهر في نموذج حجز الطيران (عملة تسعير المورد) عبر واجهة GET /api/v1/settings/currencies — سعر الصرف هنا مقابل الجنيه المصري (EGP).')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->schema([
                        TextInput::make('code')
                            ->label('رمز ISO')
                            ->required()
                            ->length(3)
                            ->unique(ignoreRecord: true)
                            ->hint('مثل EGP، USD، KWD')
                            ->rules(['regex:/^[A-Za-z]{3}$/'])
                            ->dehydrateStateUsing(fn ($state): string => is_string($state) ? strtoupper($state) : ''),

                        TextInput::make('name_ar')
                            ->label('الاسم بالعربية')
                            ->required()
                            ->maxLength(50),

                        TextInput::make('name_en')
                            ->label('الاسم بالإنجليزية')
                            ->required()
                            ->maxLength(50),

                        TextInput::make('symbol')
                            ->label('الرمز المعروض')
                            ->required()
                            ->maxLength(10)
                            ->hint('مثل ج.م، $، د.ك'),

                        TextInput::make('exchange_rate')
                            ->label('سعر الصرف')
                            ->required()
                            ->numeric()
                            ->default(1.0000)
                            ->step(0.0001)
                            ->minValue(0)
                            ->suffix('مقابل 1 وحدة أجنبية → جنيه'),

                        TextInput::make('order')
                            ->label('ترتيب العرض')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),

                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('order')
                    ->label('الترتيب')
                    ->sortable(),

                TextColumn::make('code')
                    ->label('الرمز')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('success'),

                TextColumn::make('name_ar')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Currency $record): string => $record->name_en),

                TextColumn::make('symbol')
                    ->label('الرمز')
                    ->toggleable(),

                TextColumn::make('exchange_rate')
                    ->label('سعر الصرف')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->defaultSort('order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
            ])
            ->recordActions([
                EditAction::make()->modal(false),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            'create' => CreateCurrency::route('/create'),
            'edit' => EditCurrency::route('/{record}/edit'),
        ];
    }
}
