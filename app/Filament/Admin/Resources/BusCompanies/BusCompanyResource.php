<?php

namespace App\Filament\Admin\Resources\BusCompanies;

use App\Filament\Admin\Concerns\BelongsToBusModuleNavigation;
use App\Models\Bus\BusCompany;
use BackedEnum;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BusCompanyResource extends Resource
{
    use BelongsToBusModuleNavigation;

    protected static ?string $model = BusCompany::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static string|\UnitEnum|null $navigationGroup = 'الباصات';

    protected static ?string $navigationLabel = 'شركات الباص';
    protected static ?string $pluralLabel = 'شركات الباص';
    protected static ?string $modelLabel = 'شركة باص';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('inventories')
            ->withSum('inventories', 'remaining_debt');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('busCompanyTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('identity')
                            ->label('الهوية')
                            ->icon(Heroicon::OutlinedBuildingOffice)
                            ->schema([
                                Section::make('بيانات الشركة')
                                    ->description('اسم الشركة كما يظهر في واجهة الموظفين وحجوزات الباص.')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('اسم الشركة')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('مثال: شركة النيل للنقل'),
                                        Select::make('account_id')
                                            ->label('الحساب المالي المرتبط')
                                            ->relationship('account', 'name')
                                            ->searchable()
                                            ->preload(),
                                    ]),
                            ]),
                        Tab::make('contact')
                            ->label('التواصل')
                            ->icon(Heroicon::OutlinedPhone)
                            ->schema([
                                Section::make('طرق التواصل والعنوان')
                                    ->schema([
                                        TextInput::make('phone')
                                            ->label('رقم الهاتف')
                                            ->tel()
                                            ->maxLength(20)
                                            ->placeholder('010…'),
                                        TextInput::make('address')
                                            ->label('العنوان')
                                            ->maxLength(500)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('status')
                            ->label('التشغيل والملاحظات')
                            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                            ->schema([
                                Section::make('الحالة')
                                    ->description('الشركات غير النشطة لا تظهر في نماذج الحجز العامة.')
                                    ->schema([
                                        Select::make('is_active')
                                            ->label('تفعيل الشركة')
                                            ->options([
                                                true => 'نشط',
                                                false => 'غير نشط',
                                            ])
                                            ->default(true)
                                            ->required()
                                            ->native(false),
                                        Textarea::make('notes')
                                            ->label('ملاحظات داخلية')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(1),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('name', 'اسم الشركة')
                                    ->searchable()
                                    ->sortable(),

                                TextColumn::make('account.balance', 'الرصيد المالي')
                                    ->money('egp')
                                    ->sortable()
                                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),

                                TextColumn::make('phone', 'الهاتف')
                    ->searchable(),

                BadgeColumn::make('is_active', 'الحالة')
                    ->colors([
                        true => 'success',
                        false => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => $state ? 'نشط' : 'غير نشط'),

                TextColumn::make('inventories_count', 'عدد الرحلات')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('inventories_sum_remaining_debt', 'إجمالي المديونية (رحلات)')
                    ->money('EGP')
                    ->color('danger')
                    ->sortable(),

                TextColumn::make('created_at', 'تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active', 'الحالة')
                    ->options([
                        true => 'نشط',
                        false => 'غير نشط',
                    ]),

                TrashedFilter::make(),
            ])
            ->defaultSort('name', 'asc')
            ->recordActions([
                Action::make('statement')
                    ->label('كشف الحساب')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn ($record) => $record->account_id !== null)
                    ->url(fn ($record): string => \App\Filament\Admin\Resources\Transactions\Pages\AccountStatement::getUrl(['accountId' => $record->account_id])),
                Action::make('debts')
                    ->label('مديونيات (الآجل)')
                    ->icon('heroicon-o-exclamation-circle')
                    ->color('warning')
                    ->visible(fn ($record): bool => (float) ($record->inventories_sum_remaining_debt ?? 0) > 0)
                    ->url(fn ($record): string => \App\Filament\Admin\Pages\BusCompanyDebtStatement::getUrl(['companyId' => $record->id])),
                Action::make('trips')
                    ->label('الرحلات والأسعار')
                    ->icon('heroicon-o-calendar-days')
                    ->color('success')
                    ->url(fn ($record): string => \App\Filament\Admin\Resources\BusInventories\BusInventoryResource::getUrl('index', [
                        'tableFilters[company_id][value]' => $record->id,
                    ])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                \Filament\Tables\Actions\CreateAction::make(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => Pages\ManageBusCompanies::route('/'),
        ];
    }
}
