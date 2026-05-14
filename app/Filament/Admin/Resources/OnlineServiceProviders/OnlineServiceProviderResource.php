<?php

namespace App\Filament\Admin\Resources\OnlineServiceProviders;

use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\OnlineServiceProviders\Pages\CreateOnlineServiceProvider;
use App\Filament\Admin\Resources\OnlineServiceProviders\Pages\EditOnlineServiceProvider;
use App\Filament\Admin\Resources\OnlineServiceProviders\Pages\ListOnlineServiceProviders;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Online\OnlineServiceProvider;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
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

class OnlineServiceProviderResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = OnlineServiceProvider::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'مزودو الخدمات';

    protected static ?string $pluralLabel = 'مزودو الخدمات الأونلاين';

    protected static ?string $modelLabel = 'مزود خدمة أونلاين';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('تعريف المزود')
                    ->description('الكود يستخدم في الواجهات. مثال: fawry / cash / gov_portal')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->schema([
                        TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(80)
                            ->unique(ignoreRecord: true)
                            ->rules(['regex:/^[a-zA-Z0-9_\-]+$/'])
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
                            ->default('#6B7280'),

                        TextInput::make('icon')
                            ->label('أيقونة')
                            ->maxLength(255),

                        TextInput::make('order')
                            ->label('ترتيب العرض')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),

                        Textarea::make('description_ar')
                            ->label('وصف عربي')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('description_en')
                            ->label('وصف إنجليزي')
                            ->rows(2)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),

                Section::make('بيانات تواصل وتسوية')
                    ->description('حساب التسوية الافتراضي يُستخدم لقيد التكلفة عند تنفيذ معاملة. لو فاضي يُسحب من حساب التحصيل.')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->schema([
                        TextInput::make('contact_phone')
                            ->label('رقم تواصل')
                            ->maxLength(64),

                        TextInput::make('contact_account')
                            ->label('رقم/كود حساب لدى المزود')
                            ->maxLength(128),

                        Select::make('default_purchase_account_id')
                            ->label('حساب التسوية الافتراضي')
                            ->relationship('defaultPurchaseAccount', 'name', fn ($query) => $query->where('is_active', true))
                            ->searchable()
                            ->preload(),

                        KeyValue::make('metadata')
                            ->label('بيانات إضافية')
                            ->keyLabel('المفتاح')
                            ->valueLabel('القيمة')
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

                TextColumn::make('contact_phone')
                    ->label('تواصل')
                    ->toggleable(),

                TextColumn::make('defaultPurchaseAccount.name')
                    ->label('حساب التسوية')
                    ->placeholder('—'),

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
            'index' => ListOnlineServiceProviders::route('/'),
            'create' => CreateOnlineServiceProvider::route('/create'),
            'edit' => EditOnlineServiceProvider::route('/{record}/edit'),
        ];
    }
}
