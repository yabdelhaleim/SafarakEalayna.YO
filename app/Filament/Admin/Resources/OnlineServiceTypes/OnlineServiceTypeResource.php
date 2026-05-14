<?php

namespace App\Filament\Admin\Resources\OnlineServiceTypes;

use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\OnlineServiceTypes\Pages\CreateOnlineServiceType;
use App\Filament\Admin\Resources\OnlineServiceTypes\Pages\EditOnlineServiceType;
use App\Filament\Admin\Resources\OnlineServiceTypes\Pages\ListOnlineServiceTypes;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Online\OnlineServiceType;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
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

class OnlineServiceTypeResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = OnlineServiceType::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'أنواع الخدمات';

    protected static ?string $pluralLabel = 'أنواع الخدمات الأونلاين';

    protected static ?string $modelLabel = 'نوع خدمة أونلاين';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('تعريف نوع الخدمة')
                    ->description('الكود فريد ويُستخدم في الواجهات والتقارير. مثال: travel_permit')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->schema([
                        TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(80)
                            ->unique(ignoreRecord: true)
                            ->rules(['regex:/^[a-zA-Z0-9_\-]+$/'])
                            ->helperText('حروف إنجليزية وأرقام فقط، بدون مسافات.')
                            ->dehydrateStateUsing(fn ($state) => is_string($state)
                                ? preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(['-', ' '], '_', trim($state)))) ?? ''
                                : ''),

                        TextInput::make('name_ar')
                            ->label('الاسم بالعربية')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('name_en')
                            ->label('الاسم بالإنجليزية')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('color')
                            ->label('لون العرض')
                            ->maxLength(20)
                            ->default('#6B7280')
                            ->placeholder('#F59E0B'),

                        TextInput::make('icon')
                            ->label('أيقونة (heroicon)')
                            ->maxLength(255)
                            ->placeholder('heroicon-o-identification'),

                        TextInput::make('order')
                            ->label('ترتيب العرض')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),

                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),

                        Textarea::make('description_ar')
                            ->label('وصف عربي')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('description_en')
                            ->label('وصف إنجليزي')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name_ar')
            ->columns([
                TextColumn::make('order')
                    ->label('ترتيب')
                    ->sortable(),

                TextColumn::make('code')
                    ->label('الكود')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_ar')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_en')
                    ->label('English')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('color')
                    ->label('اللون')
                    ->formatStateUsing(fn ($state) => $state ?? '—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),

                TextColumn::make('transactions_count')
                    ->label('المعاملات')
                    ->counts('transactions')
                    ->numeric()
                    ->color('gray'),
            ])
            ->defaultSort('order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('الحالة')
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
            'index' => ListOnlineServiceTypes::route('/'),
            'create' => CreateOnlineServiceType::route('/create'),
            'edit' => EditOnlineServiceType::route('/{record}/edit'),
        ];
    }
}
