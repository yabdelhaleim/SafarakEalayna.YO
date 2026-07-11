<?php

namespace App\Filament\Admin\Resources\BusCompanies;

use App\Filament\Admin\Concerns\BelongsToBusModuleNavigation;
use App\Models\Bus\BusCompany;
use App\Services\Bus\BusCompanyService;
use BackedEnum;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
use Illuminate\Database\Eloquent\Collection;
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
                                            ->searchable(['name']),
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
                    ->url(fn ($record): string => \App\Filament\Admin\Pages\AccountStatement::getUrl(['accountId' => $record->account_id])),
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
                // ✅ Unified deletion path — routes through BusCompanyService::deleteCompany(),
                // which wraps in BusCompany::run() so the ModelDeletionGuard's `deleting`
                // observer allows the soft-delete. Direct `$record->delete()` is blocked.
                Action::make('deleteCompany')
                    ->label('حذف')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف شركة الباص')
                    ->modalDescription(
                        'سيتم حذف الشركة (soft-delete) ولن تظهر في القوائم. '
                        .'يجب ألّا تكون هناك رحلات نشطة مرتبطة بها. '
                        .'لا يمكن التراجع عن هذا الإجراء عبر الواجهة.'
                    )
                    ->modalSubmitActionLabel('نعم، احذف الشركة')
                    ->action(function (BusCompany $record): void {
                        try {
                            app(BusCompanyService::class)->deleteCompany($record);

                            Notification::make()
                                ->title('تم حذف الشركة')
                                ->body('تم أرشفة الشركة ولن تظهر في القوائم.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('فشل حذف الشركة')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            throw $e;
                        }
                    }),
            ])
            ->toolbarActions([
                \Filament\Tables\Actions\CreateAction::make(),
                BulkActionGroup::make([
                    // ✅ Unified bulk deletion path — same service delegation as the
                    // single-record action above. Per-record errors are reported via
                    // Notification instead of aborting the whole batch.
                    BulkAction::make('deleteCompanies')
                        ->label('حذف المحدد')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('حذف شركات الباص المحددة')
                        ->modalDescription(
                            'سيتم حذف الشركات المحددة عبر BusCompanyService::deleteCompany(). '
                            .'أي شركة بها رحلات نشطة ستفشل مع تقرير خطأ منفصل.'
                        )
                        ->modalSubmitActionLabel('نعم، احذف المحدد')
                        ->action(function (Collection $records): void {
                            $service = app(BusCompanyService::class);
                            $success = 0;
                            $failures = [];

                            foreach ($records as $record) {
                                try {
                                    $service->deleteCompany($record);
                                    $success++;
                                } catch (\Throwable $e) {
                                    $failures[] = [
                                        'name' => $record->name,
                                        'message' => $e->getMessage(),
                                    ];
                                }
                            }

                            if ($success > 0) {
                                Notification::make()
                                    ->title("تم حذف {$success} شركة بنجاح")
                                    ->success()
                                    ->send();
                            }

                            foreach ($failures as $fail) {
                                Notification::make()
                                    ->title('فشل حذف: '.$fail['name'])
                                    ->body($fail['message'])
                                    ->danger()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InventoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusCompanies::route('/'),
            'create' => Pages\CreateBusCompany::route('/create'),
            'edit' => Pages\EditBusCompany::route('/{record}/edit'),
        ];
    }
}
