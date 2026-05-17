<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TreasuryResource\Pages;
use App\Models\Treasury;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TreasuryResource extends Resource
{
    protected static ?string $model = Treasury::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'خزائن الاسترجاع';

    protected static ?string $modelLabel = 'خزينة';

    protected static ?string $pluralModelLabel = 'خزائن الاسترجاع';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('معلومات الخزينة')
                    ->description('إعدادات وتفاصيل خزينة الاسترجاع المستقلة')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('اسم الخزينة')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('مثال: خزينة الدينار الكويتي الرئيسية'),

                                Forms\Components\TextInput::make('currency')
                                    ->label('عملة الخزينة')
                                    ->required()
                                    ->maxLength(3)
                                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                    ->helperText('يجب إدخال كود العملة القياسي من 3 أحرف (مثال: KWD, USD, EGP)'),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('current_balance')
                                    ->label('الرصيد الحالي')
                                    ->required()
                                    ->numeric()
                                    ->default(0.00)
                                    ->prefix('$'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('تفعيل الخزينة')
                                    ->default(true)
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الخزينة')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('currency')
                    ->label('العملة')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('current_balance')
                    ->label('الرصيد الحالي')
                    ->numeric(2)
                    ->sortable()
                    ->color(fn (Treasury $record): string => $record->current_balance < 0 ? 'danger' : 'success'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('الحالة')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTreasuries::route('/'),
            'create' => Pages\CreateTreasury::route('/create'),
            'edit' => Pages\EditTreasury::route('/{record}/edit'),
        ];
    }
}
