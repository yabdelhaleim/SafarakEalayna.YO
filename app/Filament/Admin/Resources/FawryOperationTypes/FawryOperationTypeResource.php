<?php

namespace App\Filament\Admin\Resources\FawryOperationTypes;

use App\Filament\Admin\Concerns\BelongsToFawryModuleNavigation;
use App\Filament\Admin\Resources\FawryOperationTypes\Pages\CreateFawryOperationType;
use App\Filament\Admin\Resources\FawryOperationTypes\Pages\EditFawryOperationType;
use App\Filament\Admin\Resources\FawryOperationTypes\Pages\ListFawryOperationTypes;
use App\Filament\Admin\Support\FawryModuleNavigation;
use App\Models\Fawry\FawryOperationType;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
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

class FawryOperationTypeResource extends Resource
{
    use BelongsToFawryModuleNavigation;

    protected static ?string $model = FawryOperationType::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = FawryModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'أنواع العمليات';

    protected static ?string $pluralLabel = 'أنواع عمليات فوري';

    protected static ?string $modelLabel = 'نوع عملية فوري';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('تعريف نوع العملية')
                    ->description('الرمز البرمجي يُستخدم في واجهة التطبيق وفي التقارير؛ يجب أن يكون فريداً بالإنجليزية بدون مسافات.')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->schema([
                        TextInput::make('code')
                            ->label('الرمز (code)')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true)
                            ->rules(['regex:/^[a-zA-Z0-9_-]+$/'])
                            ->helperText('حروف إنجليزية وأرقام و _ أو - فقط (يُحفظ كصغير وشرطة سفلية). مثل: withdrawal أو deposit')
                            ->dehydrateStateUsing(function ($state): string {
                                if (! is_string($state)) {
                                    return '';
                                }
                                $normalized = strtolower(str_replace('-', '_', trim($state)));

                                return preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';
                            }),

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
                            ->default('#6B7280'),

                        TextInput::make('icon')
                            ->label('أيقونة (اختياري)')
                            ->maxLength(255),

                        Textarea::make('description_ar')
                            ->label('وصف عربي')
                            ->rows(2),

                        Textarea::make('description_en')
                            ->label('وصف إنجليزي')
                            ->rows(2),

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
            ->recordTitleAttribute('name_ar')
            ->columns([
                TextColumn::make('order')
                    ->label('الترتيب')
                    ->sortable(),

                TextColumn::make('code')
                    ->label('الرمز')
                    ->searchable()
                    ->badge()
                    ->sortable(),

                TextColumn::make('name_ar')
                    ->label('الاسم')
                    ->searchable()
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
            'index' => ListFawryOperationTypes::route('/'),
            'create' => CreateFawryOperationType::route('/create'),
            'edit' => EditFawryOperationType::route('/{record}/edit'),
        ];
    }
}
