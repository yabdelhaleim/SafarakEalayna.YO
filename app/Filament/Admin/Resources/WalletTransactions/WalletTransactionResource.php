<?php

namespace App\Filament\Admin\Resources\WalletTransactions;

use App\Enums\AccountType;
use App\Enums\WalletTransactionType;
use App\Filament\Admin\Concerns\BelongsToWalletModuleNavigation as WalletModuleNavigationConcern;
use App\Filament\Admin\Resources\WalletTransactions\Pages\CreateWalletTransaction;
use App\Filament\Admin\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Admin\Resources\WalletTransactions\Pages\ViewWalletTransaction;
use App\Filament\Admin\Support\WalletModuleNavigation;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use BackedEnum;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletTransactionResource extends Resource
{
    use WalletModuleNavigationConcern;

    protected static ?string $model = WalletTransaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = WalletModuleNavigation::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'عمليات المحافظ';

    protected static ?string $modelLabel = 'عملية محفظة';

    protected static ?string $pluralModelLabel = 'عمليات المحافظ';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'customer_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['walletType', 'customer', 'employee', 'walletAccount', 'cashAccount']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات العملية')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->schema([
                        Select::make('type')
                            ->label('نوع العملية')
                            ->options(collect(WalletTransactionType::cases())->mapWithKeys(
                                fn (WalletTransactionType $c): array => [$c->value => $c->label()]
                            )->all())
                            ->required()
                            ->live()
                            ->helperText(fn (?string $state): ?string => match ($state) {                                'send' => 'إرسال: الوكالة ترسل للعميل وتستلم نقديًا + الخدمة',                                'receive' => 'استقبال: العميل يحول للوكالة وتدفع له نقديًا − الخدمة',                                default => null,
                            }),

                        Select::make('wallet_type_id')
                            ->label('نوع المحفظة')
                            ->options(fn (): array => WalletType::query()->active()->orderBy('sort_order')->get()->mapWithKeys(
                                fn (WalletType $w) => [$w->getKey() => (filled($w->name) ? $w->name : '— #'.$w->getKey())]
                            )->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('بيانات العميل')
                    ->schema([
                        Select::make('customer_id')
                            ->label('العميل (من السجل)')
                            ->relationship('customer', 'full_name')
                            ->getOptionLabelFromRecordUsing(fn (Customer $record): string => filled($record->full_name)
                                ? $record->full_name
                                : (filled($record->phone) ? 'عميل — '.$record->phone : 'عميل #'.$record->getKey()))
                            ->searchable(['full_name', 'phone'])
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                if ($state) {
                                    $cust = Customer::query()->find($state);
                                    if ($cust !== null) {
                                        $set('customer_name', (string) ($cust->full_name ?? ''));
                                    }
                                }
                            }),

                        TextInput::make('customer_name')
                            ->label('اسم العميل')
                            ->required()
                            ->maxLength(200),

                        TextInput::make('wallet_number')
                            ->label('رقم المحفظة (الهاتف)')
                            ->tel()
                            ->required()
                            ->maxLength(30)
                            ->placeholder('010xxxxxxxx'),
                    ])
                    ->columns(3),

                Section::make('المبالغ')
                    ->schema([
                        TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix('ج.م'),

                        TextInput::make('service_fee')
                            ->label('قيمة الخدمة (العمولة)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->suffix('ج.م'),
                    ])
                    ->columns(2),

                Section::make('الحسابات')
                    ->schema([
                        Select::make('wallet_account_id')
                            ->label('حساب المحفظة الإلكترونية (الوكالة)')
                            ->options(fn (): array => Account::query()
                                ->where('type', AccountType::Wallet)
                                ->where('module_type', 'wallet_transfer')
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Account $a) => [$a->getKey() => (filled($a->name) ? $a->name : '— #'.$a->getKey())])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        Select::make('cash_account_id')
                            ->label('الحساب النقدي')
                            ->options(fn (): array => Account::query()
                                ->whereIn('type', [AccountType::Cashbox, AccountType::Bank])
                                ->where('module_type', 'wallet_transfer')
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Account $a) => [$a->getKey() => (filled($a->name) ? $a->name : '— #'.$a->getKey())])
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('إضافي')
                    ->collapsed()
                    ->schema([
                        Select::make('employee_id')
                            ->label('الموظف')
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
                            ->searchable(['full_name'])
                            ->nullable(),

                        Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(3)
                            ->maxLength(1000),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تفاصيل العملية')
                ->columns(3)
                ->schema([
                    TextEntry::make('type')
                        ->label('نوع العملية')
                        ->formatStateUsing(fn ($state): string => WalletTransactionType::tryFrom((string) $state)?->label() ?? (string) $state)
                        ->badge(),

                    TextEntry::make('walletType.name')
                        ->label('نوع المحفظة'),

                    TextEntry::make('created_at')
                        ->label('التاريخ')
                        ->dateTime('d/m/Y H:i:s'),

                    TextEntry::make('customer_name')
                        ->label('اسم العميل'),

                    TextEntry::make('wallet_number')
                        ->label('رقم المحفظة')
                        ->copyable(),

                    TextEntry::make('employee.full_name')
                        ->label('الموظف'),
                ]),

            Section::make('المالية')
                ->columns(3)
                ->schema([
                    TextEntry::make('amount')
                        ->label('المبلغ')
                        ->money('EGP'),

                    TextEntry::make('service_fee')
                        ->label('الخدمة')
                        ->money('EGP'),

                    TextEntry::make('total_amount')
                        ->label('الإجمالي')
                        ->money('EGP'),

                    TextEntry::make('walletAccount.name')
                        ->label('حساب المحفظة'),

                    TextEntry::make('cashAccount.name')
                        ->label('الحساب النقدي'),
                ]),

            Section::make('ملاحظات')
                ->collapsed()
                ->schema([
                    TextEntry::make('notes')
                        ->label('ملاحظات')
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('customer_name')
            ->columns([
                TextColumn::make('id', '#')
                    ->sortable(),

                TextColumn::make('type', 'النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WalletTransactionType::tryFrom((string) $state)?->label() ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {                        'send' => 'warning',                        'receive' => 'success',                        default => 'gray',
                    }),

                TextColumn::make('walletType.name', 'المحفظة')
                    ->badge(),

                TextColumn::make('customer_name', 'العميل')
                    ->searchable(),

                TextColumn::make('wallet_number', 'الرقم')
                    ->copyable(),

                TextColumn::make('amount', 'المبلغ')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('service_fee', 'الخدمة')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('total_amount', 'الإجمالي')
                    ->money('egp')
                    ->sortable(),

                TextColumn::make('created_at', 'التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('نوع العملية')
                    ->options(collect(WalletTransactionType::cases())->mapWithKeys(
                        fn (WalletTransactionType $c): array => [$c->value => $c->label()]
                    )->all()),

                SelectFilter::make('wallet_type_id')
                    ->label('نوع المحفظة')
                    ->relationship('walletType', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->label('حذف مع عكس القيود')
                    ->action(function (WalletTransaction $record): void {
                        app(WalletTransactionService::class)->deleteTransaction($record);
                        Notification::make()->title('تم الحذف مع عكس القيود')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
            'create' => CreateWalletTransaction::route('/create'),
            'view' => ViewWalletTransaction::route('/{record}'),
        ];
    }
}
