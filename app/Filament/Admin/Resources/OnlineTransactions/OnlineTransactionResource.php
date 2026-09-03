<?php

namespace App\Filament\Admin\Resources\OnlineTransactions;

use App\Enums\OnlineTransactionStatus;
use App\Filament\Admin\Concerns\BelongsToOnlineModuleNavigation;
use App\Filament\Admin\Resources\OnlineTransactions\Pages\ManageOnlineTransactions;
use App\Filament\Admin\Support\OnlineModuleNavigation;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Setting\PaymentMethod;
use App\Services\Online\OnlineTransactionService;
use App\Support\Finance\AccountModuleContract;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Filament\Admin\Resources\OnlineTransactions\Widgets\OnlineStats;
use Illuminate\Database\Eloquent\Model;

class OnlineTransactionResource extends Resource
{
    use BelongsToOnlineModuleNavigation;

    protected static ?string $model = OnlineTransaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = OnlineModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'المعاملات';

    protected static ?string $pluralLabel = 'معاملات أونلاين';

    protected static ?string $modelLabel = 'معاملة أونلاين';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'customer_name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('الخدمة والمزود')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('service_type_code')
                                ->label('نوع الخدمة')
                                ->required()
                                ->maxLength(80)
                                ->placeholder('مثال: طوابع وضرائب، تصديقات، تأشيرات')
                                ->helperText('اكتب نوع الخدمة كنص حر (يمكن إدارة الأنواع من موديول الخدمات الإلكترونية > أنواع الخدمات).')
                                ->datalist(
                                    fn (): array => OnlineServiceType::query()
                                        ->orderBy('order')
                                        ->pluck('name_ar')
                                        ->all()
                                ),

                            TextInput::make('provider_code')
                                ->label('المزود')
                                ->maxLength(80)
                                ->placeholder('مثال: شركة ممتاز، اعتماد، مسارات')
                                ->helperText('اختياري — اكتب اسم المزود كنص حر.')
                                ->datalist(
                                    fn (): array => OnlineServiceProvider::query()
                                        ->orderBy('order')
                                        ->pluck('name_ar')
                                        ->all()
                                ),
                        ]),
                    ]),

                Section::make('بيانات العميل')
                    ->icon(Heroicon::OutlinedUser)
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('customer_id')
                                ->label('العميل المسجل')
                                ->relationship('customer', 'full_name')
                                ->getOptionLabelFromRecordUsing(fn (Customer $record): string => filled($record->full_name)
                                    ? $record->full_name
                                    : (filled($record->phone) ? 'عميل — '.$record->phone : 'عميل #'.$record->getKey()))
                                ->searchable(['full_name', 'phone']),

                            TextInput::make('customer_name')
                                ->label('اسم العميل')
                                ->maxLength(255)
                                ->helperText('يُملأ تلقائياً من العميل المسجل عند اختياره.'),

                            TextInput::make('customer_phone')
                                ->label('تليفون')
                                ->maxLength(64),

                            TextInput::make('customer_country')
                                ->label('البلد')
                                ->maxLength(120),
                        ]),

                        Select::make('employee_id')
                            ->label('الموظف المنفذ')
                            ->relationship('employee', 'full_name')
                            ->getOptionLabelFromRecordUsing(function (Employee $record): string {
                                if (filled($record->full_name)) {
                                    return $record->full_name;
                                }
                                $composed = trim(($record->first_name ?? '').' '.($record->last_name ?? ''));
                                if ($composed !== '') {
                                    return $composed;
                                }

                                return filled($record->user?->name)
                                    ? $record->user->name
                                    : 'موظف #'.$record->getKey();
                            })
                            ->searchable(['full_name']),
                    ]),

                Section::make('التسعير')
                    ->description('الربح يُحسب تلقائياً = سعر البيع − سعر الشراء')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('purchase_price')
                                ->label('سعر الشراء')
                                ->numeric()
                                ->prefix('ج.م')
                                ->step(0.01)
                                ->required(),

                            TextInput::make('selling_price')
                                ->label('سعر البيع')
                                ->numeric()
                                ->prefix('ج.م')
                                ->step(0.01)
                                ->required(),

                            TextInput::make('profit')
                                ->label('الربح')
                                ->numeric()
                                ->prefix('ج.م')
                                ->step(0.01)
                                ->disabled()
                                ->dehydrated(false),
                        ]),
                    ]),

                Section::make('الدفع والحالة')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->schema([
                        // ⬇️ جديد 2026-09-03: يظهر فقط لو مفيش حسابات سيولة في قسم المكتب/الأونلاين.
                        //    يحلّ مشكلة dropdown فاضي بعد تعطيل migration 2026_08_28_130000_seed_online_module_accounts.
                        Placeholder::make('no_office_accounts_warning')
                            ->label('لا توجد حسابات تحصيل')
                            ->content(new \Illuminate\Support\HtmlString(
                                'لا توجد حسابات تحصيل نشطة في قسم المكتب. '
                                .'أضف حساباً من <a href="/admin/online-wallets" class="underline font-semibold">محافظ الأونلاين</a> '
                                .'أو <a href="/admin/online-bank-accounts" class="underline font-semibold">البنوك</a> أولاً، '
                                .'ثم أكمل تسجيل المعاملة.'
                            ))
                            ->visible(fn (): bool => ! Account::query()
                                ->where('is_active', true)
                                ->whereIn('module_type', ['online', AccountModuleContract::OFFICE_MODULE_TYPE])
                                ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
                                ->exists()),
                        // ⬆️ جديد

                        Grid::make(2)->schema([
                            Select::make('payment_method')
                                ->label('طريقة الدفع')
                                ->options(fn (): array => PaymentMethod::query()
                                    ->where('is_active', true)
                                    ->orderBy('order')
                                    ->get()
                                    ->mapWithKeys(fn (PaymentMethod $m) => [$m->code => (filled($m->name_ar) ? $m->name_ar : $m->code)])
                                    ->all())
                                ->searchable()
                                ->required(),

                            Select::make('account_id')
                                ->label('حساب التحصيل')
                                // ✅ Phase 9 fix: restrict to Online-module accounts only.
                                //    Without this filter, users could pick any active
                                //    account across modules (bus/flights/visas/...), causing
                                //    cross-module financial pollution (the chosen vault would
                                //    be debited for an Online transaction even though it
                                //    belongs to another module's ledger).
                                ->relationship('account', 'name', fn ($q) => $q
                                    ->where('is_active', true)
                                    ->whereIn('module_type', ['online', 'office']))
                                ->getOptionLabelFromRecordUsing(fn (Account $record): string => filled($record->name)
                                    ? $record->name
                                    : 'حساب #'.$record->getKey())
                                ->searchable(['name'])
                                ->required(),

                            TextInput::make('reference_number')
                                ->label('رقم مرجع')
                                ->maxLength(255),

                            Select::make('status')
                                ->label('الحالة')
                                ->options(fn () => collect(OnlineTransactionStatus::cases())
                                    ->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all())
                                ->default(OnlineTransactionStatus::Completed->value)
                                ->required(),
                        ]),

                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('failure_reason')
                            ->label('سبب الفشل (إن وجد)')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn ($get) => $get('status') === OnlineTransactionStatus::Failed->value),
                    ]),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['serviceTypeRow', 'providerRow', 'customer', 'employee', 'account']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('customer_name')
            ->columns([
                TextColumn::make('id', 'الرقم')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('customer_name', 'العميل')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('customer_phone', 'التليفون')
                    ->toggleable(),

                // Show Arabic name from the lookup table if a matching row
                // exists in online_service_types; otherwise show the raw code.
                TextColumn::make('service_type_code', 'الخدمة')
                    ->label('الخدمة')
                    ->formatStateUsing(function ($state, $record) {
                        if (! $state) {
                            return '—';
                        }
                        $name = $record->serviceTypeRow?->name_ar;

                        return $name ?? $state;
                    })
                    ->badge()
                    ->searchable(),

                TextColumn::make('provider_code', 'المزود')
                    ->label('المزود')
                    ->formatStateUsing(function ($state, $record) {
                        if (! $state) {
                            return '—';
                        }
                        $name = $record->providerRow?->name_ar;

                        return $name ?? $state;
                    })
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('purchase_price', 'سعر الشراء')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('selling_price', 'سعر البيع')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('profit', 'الربح')
                    ->money('egp')
                    ->color('success')
                    ->sortable(),

                TextColumn::make('payment_method', 'الدفع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => PaymentMethod::where('code', $state)->value('name_ar') ?? $state),

                TextColumn::make('status', 'الحالة')
                    ->badge()
                    ->color(fn ($state) => match ($state instanceof OnlineTransactionStatus ? $state->value : $state) {                        'completed' => 'success',                        'pending' => 'warning',                        'failed' => 'danger',                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof OnlineTransactionStatus
                        ? $state->label()
                        : (OnlineTransactionStatus::tryFrom((string) $state)?->label() ?? $state)),

                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('service_type_code')
                    ->label('نوع الخدمة')
                    ->options(fn (): array => OnlineServiceType::query()->orderBy('order')->get()->mapWithKeys(
                        fn (OnlineServiceType $t) => [$t->code => (filled($t->name_ar) ? $t->name_ar : $t->code)]
                    )->all()),

                SelectFilter::make('provider_code')
                    ->label('المزود')
                    ->options(fn (): array => OnlineServiceProvider::query()->orderBy('order')->get()->mapWithKeys(
                        fn (OnlineServiceProvider $p) => [$p->code => (filled($p->name_ar) ? $p->name_ar : $p->code)]
                    )->all()),

                SelectFilter::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options(fn (): array => PaymentMethod::query()->orderBy('order')->get()->mapWithKeys(
                        fn (PaymentMethod $m) => [$m->code => (filled($m->name_ar) ? $m->name_ar : $m->code)]
                    )->all()),

                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(fn () => collect(OnlineTransactionStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->using(function (array $data, Model $record): Model {
                        if (! $record instanceof OnlineTransaction) {
                            throw new \InvalidArgumentException('Expected OnlineTransaction.');
                        }

                        return app(OnlineTransactionService::class)->update($record, $data);
                    })
                    ->successNotificationTitle('تم تحديث المعاملة')
                    ->successNotification(function (Notification $notification, OnlineTransaction $record): Notification {
                        return $notification
                            ->persistent()
                            ->body(static::apiEnvelopePreviewBody($record, 'Online transaction updated successfully.'));
                    }),
                DeleteAction::make()
                    ->using(function (Model $record): bool {
                        if (! $record instanceof OnlineTransaction) {
                            throw new \InvalidArgumentException('Expected OnlineTransaction.');
                        }

                        return app(OnlineTransactionService::class)->delete($record);
                    })
                    ->successNotificationTitle('تم حذف المعاملة'),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getWidgets(): array
    {
        return [
            OnlineStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOnlineTransactions::route('/'),
        ];
    }

    public static function apiEnvelopePreviewBody(OnlineTransaction $record, string $message): string
    {
        $record->refresh();
        $record->loadMissing(['serviceTypeRow:id,name_ar,code', 'providerRow:id,name_ar,code', 'employee:id,full_name', 'account:id,name,type']);

        $envelope = [
            'status' => true,
            'message' => $message,
            'data' => array_merge(
                $record->attributesToArray(),
                [
                    'service_type' => $record->serviceTypeRow?->only(['id', 'name_ar', 'code']),
                    'provider' => $record->providerRow?->only(['id', 'name_ar', 'code']),
                    'employee' => $record->employee?->only(['id', 'full_name']),
                    'account' => $record->account?->only(['id', 'name', 'type']),
                ],
            ),
            'errors' => null,
        ];

        return json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
