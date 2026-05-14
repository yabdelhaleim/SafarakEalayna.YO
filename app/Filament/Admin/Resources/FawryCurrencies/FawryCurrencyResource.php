<?php

namespace App\Filament\Admin\Resources\FawryCurrencies;

use App\Filament\Admin\Concerns\BelongsToFawryModuleNavigation;
use App\Filament\Admin\Resources\FawryCurrencies\Pages\CreateFawryCurrency;
use App\Filament\Admin\Resources\FawryCurrencies\Pages\EditFawryCurrency;
use App\Filament\Admin\Resources\FawryCurrencies\Pages\ListFawryCurrencies;
use App\Filament\Admin\Support\FawryModuleNavigation;
use App\Models\Fawry\FawryCurrency;
use App\Models\Setting\Currency;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

class FawryCurrencyResource extends Resource
{
    use BelongsToFawryModuleNavigation;

    protected static ?string $model = FawryCurrency::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = FawryModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'العملات المتاحة';

    protected static ?string $pluralLabel = 'عملات فوري';

    protected static ?string $modelLabel = 'عملة فوري';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): ?string
    {
        if (! $record instanceof FawryCurrency) {
            return null;
        }

        return $record->currency?->code.' — '.$record->currency?->name_ar;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('ربط العملة')
                    ->description('كل عملة من جدول العملات العامة تُضاف مرة واحدة كخيار في موديول فوري.')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->schema([
                        Select::make('currency_id')
                            ->label('العملة')
                            ->relationship('currency', 'name_ar', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn (Currency $record): string => $record->code.' — '.$record->name_ar)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->unique(ignoreRecord: true),

                        TextInput::make('exchange_rate')
                            ->label('سعر صرف فوري (مرجعي)')
                            ->numeric()
                            ->default(1)
                            ->step(0.0001)
                            ->minValue(0)
                            ->required(),

                        TextInput::make('min_amount')
                            ->label('حد أدنى للمعاملة')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0),

                        TextInput::make('max_amount')
                            ->label('حد أقصى للمعاملة')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0),

                        TextInput::make('fee_percent')
                            ->label('نسبة رسم %')
                            ->numeric()
                            ->default(0)
                            ->step(0.01)
                            ->minValue(0),

                        TextInput::make('fixed_fee')
                            ->label('رسم ثابت')
                            ->numeric()
                            ->default(0)
                            ->step(0.01)
                            ->minValue(0),

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
            ->columns([
                TextColumn::make('order')
                    ->label('الترتيب')
                    ->sortable(),

                TextColumn::make('currency.code')
                    ->label('الرمز')
                    ->searchable()
                    ->badge(),

                TextColumn::make('currency.name_ar')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

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
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFawryCurrencies::route('/'),
            'create' => CreateFawryCurrency::route('/create'),
            'edit' => EditFawryCurrency::route('/{record}/edit'),
        ];
    }
}
