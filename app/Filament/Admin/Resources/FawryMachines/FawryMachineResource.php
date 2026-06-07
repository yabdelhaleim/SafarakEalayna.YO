<?php

namespace App\Filament\Admin\Resources\FawryMachines;

use App\Filament\Admin\Support\FawryModuleNavigation;
use App\Models\Fawry\FawryMachine;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FawryMachineResource extends Resource
{
    protected static ?string $model = FawryMachine::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-computer-desktop';

    protected static string|\UnitEnum|null $navigationGroup = FawryModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'أجهزة الشحن';

    protected static ?string $pluralLabel = 'أجهزة الشحن فوري';

    protected static ?string $modelLabel = 'جهاز شحن فوري';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('تفاصيل جهاز الشحن')
                    ->icon(Heroicon::OutlinedDeviceTablet)
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم الجهاز / الماكينة')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: ماكينة المحل الرئيسي، ماكينة فودافون كاش'),

                        Select::make('type')
                            ->label('نوع الماكينة / شبكة الشحن')
                            ->options([
                                'fawry' => 'فوري (Fawry)',
                                'aman' => 'أمان (Aman)',
                                'momtaz' => 'ممتاز (Momtaz)',
                                'masary' => 'مصاري (Masary)',
                                'other' => 'أخرى (Other)',
                            ])
                            ->default('fawry')
                            ->required()
                            ->native(false),

                        TextInput::make('balance')
                            ->label('الرصيد الابتدائي')
                            ->numeric()
                            ->default(0.00)
                            ->step(0.01)
                            ->required()
                            ->disabled(fn (?FawryMachine $record) => $record !== null)
                            ->prefix('ج.م')
                            ->helperText('الرصيد الابتدائي يتم إدخاله عند الإنشاء فقط. للتعديل لاحقاً استخدم عمليات الشحن.'),

                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),

                        Textarea::make('notes')
                            ->label('ملاحظات إضافية')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('اسم الماكينة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'fawry' => 'فوري',
                        'aman' => 'أمان',
                        'momtaz' => 'ممتاز',
                        'masary' => 'مصاري',
                        'other' => 'أخرى',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'fawry' => 'success',
                        'aman' => 'warning',
                        'momtaz' => 'info',
                        'masary' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('balance')
                    ->label('الرصيد الحالي')
                    ->money('egp')
                    ->sortable()
                    ->weight('bold'),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFawryMachines::route('/'),
            'create' => Pages\CreateFawryMachine::route('/create'),
            'edit' => Pages\EditFawryMachine::route('/{record}/edit'),
        ];
    }
}
