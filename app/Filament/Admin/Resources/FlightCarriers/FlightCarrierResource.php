<?php

namespace App\Filament\Admin\Resources\FlightCarriers;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\FlightCarriers\Pages\CreateFlightCarrier;
use App\Filament\Admin\Resources\FlightCarriers\Pages\EditFlightCarrier;
use App\Filament\Admin\Resources\FlightCarriers\Pages\ListFlightCarriers;
use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Services\Flight\FlightCarrierRechargeService;
use BackedEnum;
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
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\RestoreAction;
use Filament\Tables\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class FlightCarrierResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = FlightCarrier::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'خطوط الطيران';

    protected static ?string $pluralLabel = 'خطوط الطيران';

    protected static ?string $modelLabel = 'خط طيران';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('flightCarrierTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('link')
                            ->label('الربط والهوية')
                            ->icon(Heroicon::OutlinedLink)
                            ->schema([
                                Section::make('النظام والأسماء')
                                    ->description('يمكن ربط خط الطيران بنظام حجز (GDS) كمرجع، أو تركه فارغاً إذا كان التعامل معه مباشراً.')
                                    ->schema([
                                        Select::make('flight_system_id')
                                            ->label('نظام الطيران (اختياري)')
                                            ->relationship('system', 'name')
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('name')
                                            ->label('اسم خط الطيران')
                                            ->required()
                                            ->maxLength(255)
                                            ->placeholder('الجزيرة، العربية للطيران، نسما…'),
                                        TextInput::make('code')
                                            ->label('رمز الشركة')
                                            ->required()
                                            ->maxLength(10)
                                            ->unique(ignoreRecord: true)
                                            ->placeholder('JZ، SV، NS'),
                                        TextInput::make('iata_code')
                                            ->label('رمز IATA')
                                            ->maxLength(3)
                                            ->placeholder('KWI، CAI…')
                                            ->helperText('اختياري؛ ثلاثة أحرف عند الحاجة للتقارير الدولية.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('finance')
                            ->label('المالية')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make('العملة والأرصدة')
                                    ->description(new HtmlString(
                                        'الرصيد الحالي هو ما لدى الشركة لديكم. «حد الائتمان» سقف إضافي: المتاح للخصم = الرصيد + حد الائتمان (مثل حسابات AirlineAccount في النظام).'
                                    ))
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
                                            ->label('الرصيد الحالي')
                                            ->numeric()
                                            ->default(0)
                                            ->step(0.01)
                                            ->required()
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
                                            ->helperText('يُستخدم مع الرصيد عند التحقق من إمكانية خصم تذاكر جديدة.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('extra')
                            ->label('إضافي')
                            ->icon(Heroicon::OutlinedClipboardDocumentList)
                            ->schema([
                                Section::make('حالة وتعليقات')
                                    ->schema([
                                        Textarea::make('notes')
                                            ->label('ملاحظات')
                                            ->rows(4)
                                            ->columnSpanFull(),
                                        Toggle::make('is_active')
                                            ->label('نشط')
                                            ->default(true)
                                            ->inline(false),
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
                    ->label('خط الطيران')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('system.name')
                    ->label('النظام')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('currency')
                    ->label('العملة')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('balance')
                    ->label('الرصيد')
                    ->money(fn (FlightCarrier $record): string => strtolower($record->currency))
                    ->sortable(),
                TextColumn::make('available_balance')
                    ->label('المتاح للخصم')
                    ->money(fn (FlightCarrier $record): string => strtolower($record->currency))
                    ->tooltip('الرصيد الحالي + حد الائتمان'),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('flight_system_id')
                    ->label('النظام')
                    ->relationship('system', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('currency')
                    ->label('العملة')
                    ->options([
                        'EGP' => 'جنيه مصري',
                        'KWD' => 'دينار كويتي',
                        'SAR' => 'ريال سعودي',
                        'USD' => 'دولار أمريكي',
                        'AED' => 'درهم إماراتي',
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
                    ->label('شحن رصيد')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('success')
                    ->visible(fn (FlightCarrier $record): bool => (bool) $record->is_active)
                    ->modalHeading(fn (FlightCarrier $record): string => 'شحن رصيد ناقل: '.$record->name.' ('.$record->code.')'
                    )
                    ->modalDescription(fn (FlightCarrier $record): string => 'العملة: '.$record->currency.
                        ' — الرصيد الحالي: '.number_format((float) $record->balance, 2).' '.$record->currency.
                        ' — سيُخصم المبلغ من حساب تحصيل بنفس العملة.'
                    )
                    ->form(fn (FlightCarrier $record): array => [
                        Select::make('from_account_id')
                            ->label('من حساب (محفظة / بنك / خزينة)')
                            ->options(self::accountOptionsForCarrier($record))
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->helperText('فقط الحسابات النشطة بعملة '.$record->currency.' تظهر هنا.'),
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
                    ->action(function (array $data, FlightCarrier $record): void {
                        $account = Account::query()->findOrFail((int) $data['from_account_id']);
                        $amount = (float) $data['amount'];
                        $notes = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

                        try {
                            app(FlightCarrierRechargeService::class)->rechargeFromAccount(
                                $record,
                                $account,
                                $amount,
                                $notes,
                            );
                            Notification::make()
                                ->title('تم شحن رصيد الناقل بنجاح')
                                ->body('الرصيد الجديد: '.number_format($record->fresh()->balance, 2).' '.$record->currency)
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
            'index' => ListFlightCarriers::route('/'),
            'create' => CreateFlightCarrier::route('/create'),
            'edit' => EditFlightCarrier::route('/{record}/edit'),
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
     * خيارات الحسابات المالية المتاحة لشحن رصيد الناقل (نفس العملة فقط).
     *
     * @return array<int, string>
     */
    protected static function accountOptionsForCarrier(FlightCarrier $carrier): array
    {
        $types = [
            AccountType::Cashbox->value,
            AccountType::Wallet->value,
            AccountType::Bank->value,
            AccountType::Treasury->value,
        ];

        return Account::query()
            ->where('is_active', true)
            ->where('module_type', 'flights')
            ->whereIn('type', $types)
            ->where('currency', $carrier->currency)
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
            AccountType::Treasury->value => 'خزينة عامة',
            AccountType::Bank->value => 'بنك',
            default => $typeVal,
        };

        return "{$a->name} — {$typeLabel} — {$bal} {$cur}";
    }
}
