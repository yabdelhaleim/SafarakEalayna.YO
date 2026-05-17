<?php

namespace App\Filament\Resources\FlightSystem;

use App\Filament\Resources\FlightSystem\Pages;
use App\Models\Flight\FlightSystem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlightSystemResource extends Resource
{
    protected static ?string $model = FlightSystem::class;

    protected static ?string $navigationIcon = 'heroicon-o-server';

    protected static ?string $navigationLabel = 'أنظمة الحجز';

    protected static ?string $modelLabel = 'نظام حجز';

    protected static ?string $pluralModelLabel = 'أنظمة الحجز';

    protected static ?string $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('معلومات النظام')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('اسم النظام')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: Amadeus, NDC, NDC X'),

                        Forms\Components\TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('مثال: AMA, NDC, NDCX')
                            ->helperText('كود مختصر فريد للنظام'),

                        Forms\Components\Select::make('type')
                            ->label('نوع النظام')
                            ->options([
                                'gds' => 'GDS (Global Distribution System)',
                                'ndc' => 'NDC (New Distribution Capability)',
                                'other' => 'أخرى',
                            ])
                            ->default('gds')
                            ->required(),

                        Forms\Components\Textarea::make('description')
                            ->label('الوصف')
                            ->rows(3)
                            ->placeholder('وصف مختصر عن النظام...'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('اسم النظام')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'gds' => 'success',
                        'ndc' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'gds' => 'GDS',
                        'ndc' => 'NDC',
                        default => 'أخرى',
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('carriers_count')
                    ->label('عدد الشركات')
                    ->counts('carriers')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('نوع النظام')
                    ->options([
                        'gds' => 'GDS',
                        'ndc' => 'NDC',
                        'other' => 'أخرى',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط فقط')
                    ->falseLabel('غير نشط فقط'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFlightSystems::route('/'),
            'create' => Pages\CreateFlightSystem::route('/create'),
            'edit' => Pages\EditFlightSystem::route('/{record}/edit'),
        ];
    }
}
