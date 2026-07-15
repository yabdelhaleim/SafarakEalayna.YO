<?php

namespace App\Filament\Admin\Resources\FlightSystems;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\FlightSystems\Pages\CreateFlightSystem;
use App\Filament\Admin\Resources\FlightSystems\Pages\EditFlightSystem;
use App\Filament\Admin\Resources\FlightSystems\Pages\ListFlightSystems;
use App\Filament\Admin\Resources\FlightSystems\Pages\ViewFlightSystem;
use App\Filament\Admin\Resources\FlightSystems\RelationManagers\FlightSystemBookingsRelationManager;
use App\Filament\Admin\Resources\FlightSystems\RelationManagers\FlightSystemTransactionsRelationManager;
use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Services\Flight\FlightSystemRechargeService;
use BackedEnum;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ForceDeleteAction;
use Filament\Tables\Actions\ForceDeleteBulkAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FlightSystemResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = FlightSystem::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-server-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'أنظمة الطيران';

    protected static ?string $pluralLabel = 'أنظمة الطيران';

    protected static ?string $modelLabel = 'نظام طيران';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('flightSystemTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('identity')
                            ->label('التعريف')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->schema([
                                Section::make('بيانات النظام')
                                    ->description('رمز فريد قصير (مثل AMA، NDC) يُستخدم في الربط مع شركات الطيران والحجوزات.')
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('اسم النظام')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('مثال: Amadeus، NDC، Sabre'),
                                        TextInput::make('code')
                                            ->label('الرمز')
                                            ->required()
                                            ->maxLength(20)
                                            ->unique(ignoreRecord: true)
                                            ->placeholder('AMA، NDC، SAB'),
                                        Select::make('type')
                                            ->label('تصنيف النظام')
                                            ->options([
                                                'gds' => 'GDS (توزيع عالمي)',
                                                'ndc' => 'NDC (محتوى مباشر)',
                                                'other' => 'أخرى',
                                            ])
                                            ->default('gds')
                                            ->required()
                                            ->native(false),
                                        Toggle::make('is_active')
                                            ->label('نشط')
                                            ->default(true)
                                            ->inline(false),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('finance')
                            ->label('الرصيد والائتمان')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make('إعدادات مالية للنظام')
                                    ->description('المتاح للخصم = الرصيد الحالي + حد الائتمان (سقف إضافي فوق الرصيد المشحون)، كما في حسابات شركات الطيران داخل النظام.')
                                    ->schema([
                                        Select::make('currency')
                                            ->label('العملة')
                                            ->options([
                                                'EGP' => 'جنيه مصري (EGP)',
                                                'KWD' => 'دينار كويتي (KWD)',
                                                'SAR' => 'ريال سعودي (SAR)',
                                                'USD' => 'دولار أمريكي (USD)',
                                                'AED' => 'درهم إماراتي (AED)',
                                            ])
                                            ->default('KWD')
                                            ->required()
                                            ->native(false),
                                        TextInput::make('balance')
                                            ->label('الرصيد الابتدائي / الحالي')
                                            ->numeric()
                                            ->default(0)
                                            ->step(0.01)
                                            ->required()
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->helperText('لا يمكن تعديل الرصيد مباشرة. استخدم زر "إعادة شحن" في القائمة لضمان تسجيل القيد المحاسبي الصحيح.')
                                            ->prefix(fn ($get) => match ($get('currency')) {
                                                'EGP' => 'ج.م',
                                                'KWD' => 'د.ك',
                                                'SAR' => 'ر.س',
                                                'USD' => '$',
                                                'AED' => 'د.إ',
                                                default => '',
                                            }),
                                        TextInput::make('credit_limit')
                                            ->label('حد الائتمان')
                                            ->numeric()
                                            ->default(0)
                                            ->step(0.01)
                                            ->prefix(fn ($get) => match ($get('currency')) {
                                                'EGP' => 'ج.م',
                                                'KWD' => 'د.ك',
                                                'SAR' => 'ر.س',
                                                'USD' => '$',
                                                'AED' => 'د.إ',
                                                default => '',
                                            })
                                            ->helperText('يُجمع مع الرصيد: «المتاح للخصم» = الرصيد + حد الائتمان.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('details')
                            ->label('التفاصيل والتتبع')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->schema([
                                Section::make('وصف وملاحظات داخلية')
                                    ->schema([
                                        Textarea::make('description')
                                            ->label('الوصف')
                                            ->rows(5)
                                            ->columnSpanFull()
                                            ->placeholder('واجهة الربط، القناة، ملاحظات للفريق…'),
                                    ]),
                            ]),
                    ]),
                Hidden::make('created_by')
                    ->default(fn () => auth()->id())
                    ->dehydrated(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('code')
                    ->label('الرمز')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {                        'gds' => 'GDS',                        'ndc' => 'NDC',                        'other' => 'أخرى',                        default => $state ?? '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {                        'gds' => 'info',                        'ndc' => 'success',                        default => 'gray',
                    }),
                TextColumn::make('currency')
                    ->label('العملة')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('balance')
                    ->label('الرصيد')
                    ->money(fn (FlightSystem $record): string => strtolower((string) $record->currency))
                    ->sortable(),
                TextColumn::make('available_balance')
                    ->label('المتاح')
                    ->money(fn (FlightSystem $record): string => strtolower((string) $record->currency))
                    ->tooltip('الرصيد + حد الائتمان'),
                TextColumn::make('credit_limit')
                    ->label('حد الائتمان')
                    ->money(fn (FlightSystem $record): string => strtolower((string) $record->currency))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('carriers_count')
                    ->label('شركات الطيران')
                    ->counts('carriers')
                    ->badge()
                    ->color('warning'),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('النوع')
                    ->options([
                        'gds' => 'GDS',
                        'ndc' => 'NDC',
                        'other' => 'أخرى',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->placeholder('الكل')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
                TrashedFilter::make(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('rechargeBalance')
                    ->label('إعادة شحن')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('success')
                    ->visible(fn (FlightSystem $record): bool => (bool) $record->is_active)
                    ->modalHeading(fn (FlightSystem $record): string => 'شحن رصيد: '.$record->name.' ('.$record->code.')')
                    ->modalDescription(fn (FlightSystem $record): string => 'العملة: '.$record->currency.' — يُخصم من حساب تحصيل (سياحة) بنفس العملة.')
                    ->form(fn (FlightSystem $record): array => [
                        Select::make('from_account_id')
                            ->label('من حساب (محفظة / بنك / خزينة)')
                            ->options(self::accountOptionsForSystem($record))
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->suffix($record->currency),
                        Textarea::make('notes')
                            ->label('ملاحظات (اختياري)')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (array $data, FlightSystem $record): void {
                        $account = Account::query()->findOrFail((int) $data['from_account_id']);
                        $amount = (float) $data['amount'];
                        $notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

                        try {
                            app(FlightSystemRechargeService::class)->rechargeFromAccount(
                                $record,
                                $account,
                                $amount,
                                $notes,
                            );
                            Notification::make()
                                ->title('تم شحن رصيد النظام بنجاح')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('تعذر تنفيذ الشحن')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                ViewAction::make()->modal(false),
                EditAction::make()->modal(false),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFlightSystems::route('/'),
            'create' => CreateFlightSystem::route('/create'),
            'view' => ViewFlightSystem::route('/{record}'),
            'edit' => EditFlightSystem::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            FlightSystemBookingsRelationManager::class,
            FlightSystemTransactionsRelationManager::class,
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function accountOptionsForSystem(FlightSystem $system): array
    {
        $types = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

        return Account::query()
            ->where('is_active', true)
            ->where('module_type', 'flights')
            ->whereIn('type', $types)
            ->where('currency', $system->currency)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => self::accountOptionLabel($a)])
            ->all();
    }

    protected static function accountOptionLabel(Account $a): string
    {
        $typeVal = $a->type instanceof BackedEnum ? $a->type->value : (string) $a->type;
        $bal = number_format((float) $a->balance, 2);
        $cur = $a->currency ?? 'EGP';

        if ($typeVal === AccountType::Wallet->value) {
            $prov = $a->wallet_provider instanceof BackedEnum
                ? $a->wallet_provider->value
                : (string) ($a->wallet_provider ?? '');
            $pl = WalletProvider::tryFrom($prov)?->label() ?? ($prov !== '' ? $prov : 'محفظة');
            $num = trim((string) ($a->wallet_number ?? ''));
            $mid = $num !== '' ? "{$pl} — {$num}" : $pl;

            return "{$a->name} — {$mid} — {$bal} {$cur}";
        }

        $typeLabel = match ($typeVal) {
            AccountType::Cashbox->value => 'نقدي / درج',
            AccountType::Bank->value => 'خزينة عامة',
            AccountType::Bank->value => 'بنك',
            default => $typeVal,
        };

        return "{$a->name} — {$typeLabel} — {$bal} {$cur}";
    }
}
