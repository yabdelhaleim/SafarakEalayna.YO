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
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
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
                            Select::make('service_type_id')
                                ->label('نوع الخدمة')
                                ->options(fn (): array => OnlineServiceType::query()->orderBy('order')->get()->mapWithKeys(
                                    fn (OnlineServiceType $t) => [$t->getKey() => (filled($t->name_ar) ? $t->name_ar : (filled($t->code) ? $t->code : '— #'.$t->getKey()))]
                                )->all())
                                ->searchable()
                                ->required()
                                ->preload(),

                            Select::make('provider_id')
                                ->label('المزود')
                                ->options(fn (): array => OnlineServiceProvider::query()->orderBy('order')->get()->mapWithKeys(
                                    fn (OnlineServiceProvider $p) => [$p->getKey() => (filled($p->name_ar) ? $p->name_ar : (filled($p->code) ? $p->code : '— #'.$p->getKey()))]
                                )->all())
                                ->searchable()
                                ->preload(),
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
                                ->searchable()
                                ->preload(),

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
                            ->searchable()
                            ->preload(),
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
                                ->relationship('account', 'name', fn ($q) => $q->where('is_active', true))
                                ->getOptionLabelFromRecordUsing(fn (Account $record): string => filled($record->name)
                                    ? $record->name
                                    : 'حساب #'.$record->getKey())
                                ->searchable()
                                ->preload()
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

                TextColumn::make('serviceType.name_ar', 'الخدمة')
                    ->badge()
                    ->searchable(),

                TextColumn::make('provider.name_ar', 'المزود')
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
                    ->color(fn ($state) => match ($state instanceof OnlineTransactionStatus ? $state->value : $state) {
                        'completed' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof OnlineTransactionStatus
                        ? $state->label()
                        : (OnlineTransactionStatus::tryFrom((string) $state)?->label() ?? $state)),

                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('service_type_id')
                    ->label('نوع الخدمة')
                    ->options(fn (): array => OnlineServiceType::query()->orderBy('order')->get()->mapWithKeys(
                        fn (OnlineServiceType $t) => [$t->getKey() => (filled($t->name_ar) ? $t->name_ar : (filled($t->code) ? $t->code : '—'))]
                    )->all()),

                SelectFilter::make('provider_id')
                    ->label('المزود')
                    ->options(fn (): array => OnlineServiceProvider::query()->orderBy('order')->get()->mapWithKeys(
                        fn (OnlineServiceProvider $p) => [$p->getKey() => (filled($p->name_ar) ? $p->name_ar : (filled($p->code) ? $p->code : '—'))]
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
        $record->loadMissing(['serviceType:id,name_ar,code', 'provider:id,name_ar,code', 'employee:id,full_name', 'account:id,name,type']);

        $envelope = [
            'status' => true,
            'message' => $message,
            'data' => array_merge(
                $record->attributesToArray(),
                [
                    'service_type' => $record->serviceType?->only(['id', 'name_ar', 'code']),
                    'provider' => $record->provider?->only(['id', 'name_ar', 'code']),
                    'employee' => $record->employee?->only(['id', 'full_name']),
                    'account' => $record->account?->only(['id', 'name', 'type']),
                ],
            ),
            'errors' => null,
        ];

        return json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
