<?php

namespace App\Filament\Admin\Resources\BusCompanyPayments;

use App\Enums\BusCompanyPaymentStatus;
use App\Filament\Admin\Concerns\BelongsToBusModuleNavigation;
use App\Models\Bus\BusCompanyPayment;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BusCompanyPaymentResource extends Resource
{
    use BelongsToBusModuleNavigation;

    protected static ?string $model = BusCompanyPayment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'مدفوعات الشركات';

    protected static ?string $pluralLabel = 'مدفوعات الشركات';

    protected static ?string $modelLabel = 'دفعة شركة';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'company',
            'inventory',
            'account',
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الدفع')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('company_id', 'الشركة')
                                    ->relationship('company', 'name')
                                    ->searchable()
                                    ->required(),

                                Select::make('inventory_id', 'الرحلة')
                                    ->relationship(
                                        'inventory',
                                        'route',
                                        fn ($query) => $query->with('company')
                                    )
                                    ->getOptionLabelFromRecordUsing(
                                        fn (Model $record) => ($record->company?->name ?? '—').' — '.$record->route.' ('.($record->travel_date?->format('d/m/Y') ?? '').')'
                                    )
                                    ->searchable(['route'])
                                    ->preload(),

                                TextInput::make('amount', 'المبلغ')
                                    ->numeric()
                                    ->prefix('ج.م')
                                    ->step(0.01)
                                    ->required(),

                                Select::make('account_id', 'الحساب')
                                    ->relationship('account', 'name', fn ($query) => $query->where('is_active', true))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Select::make('status', 'الحالة')
                                    ->options(BusCompanyPaymentStatus::class)
                                    ->default(BusCompanyPaymentStatus::Pending)
                                    ->required(),

                                Textarea::make('notes', 'ملاحظات')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('company.name', 'الشركة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('inventory.route', 'الرحلة')
                    ->limit(20)
                    ->placeholder('عام'),

                TextColumn::make('amount', 'المبلغ')
                    ->money('EGP')
                    ->sortable()
                    ->color('success'),

                TextColumn::make('status', 'الحالة')
                    ->badge()
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),

                TextColumn::make('account.name', 'الحساب')
                    ->searchable(),

                TextColumn::make('created_at', 'تاريخ الدفع')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('company_id', 'الشركة')
                    ->relationship('company', 'name')
                    ->searchable(),

                SelectFilter::make('status', 'الحالة')
                    ->options(BusCompanyPaymentStatus::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                \Filament\Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ManageBusCompanyPayments::route('/'),
        ];
    }
}
