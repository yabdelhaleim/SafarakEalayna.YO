<?php

namespace App\Filament\Admin\Resources\WalletTypes;

use App\Filament\Admin\Concerns\BelongsToWalletModuleNavigation;
use App\Filament\Admin\Resources\WalletTypes\Pages\CreateWalletType;
use App\Filament\Admin\Resources\WalletTypes\Pages\EditWalletType;
use App\Filament\Admin\Resources\WalletTypes\Pages\ListWalletTypes;
use App\Filament\Admin\Support\WalletModuleNavigation;
use App\Models\Wallet\WalletType;
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

class WalletTypeResource extends Resource
{
    use BelongsToWalletModuleNavigation;

    protected static ?string $model = WalletType::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = WalletModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'أنواع المحافظ';

    protected static ?string $modelLabel = 'نوع محفظة';

    protected static ?string $pluralModelLabel = 'أنواع المحافظ';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات نوع المحفظة')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->schema([
                        TextInput::make('name')
                            ->label('الاسم')
                            ->required()
                            ->maxLength(100)
                            ->placeholder('فودافون كاش، انستاباي…'),

                        TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(50)
                            ->helperText('رمز فريد بالإنجليزية: vodafone_cash'),

                        TextInput::make('sort_order')
                            ->label('الترتيب')
                            ->numeric()
                            ->default(0),

                        Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return static::form($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('code')
                    ->label('الكود')
                    ->badge()
                    ->searchable(),

                TextColumn::make('transactions_count')
                    ->label('العمليات')
                    ->counts('transactions')
                    ->badge(),

                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('نشط'),
            ])
            ->recordActions([
                EditAction::make()->modal(false),
                DeleteAction::make()
                    ->before(function (WalletType $record): void {
                        if ($record->transactions()->exists()) {
                            throw new \RuntimeException('لا يمكن حذف نوع المحفظة لوجود عمليات مرتبطة.');
                        }
                    }),
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
            'index' => ListWalletTypes::route('/'),
            'create' => CreateWalletType::route('/create'),
            'edit' => EditWalletType::route('/{record}/edit'),
        ];
    }
}
