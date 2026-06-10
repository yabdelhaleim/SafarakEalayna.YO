<?php

namespace App\Filament\Admin\Resources\Accounts;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Services\Finance\AccountRechargeService;
use App\Support\Finance\AccountModuleDivision;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AccountFormSchema
{
    public static function configure(Schema $schema, ?AccountType $fixedType = null, string $defaultModule = 'general', bool $lockModuleType = false): Schema
    {
        $definitionFields = [
            TextInput::make('name')
                ->label('اسم الحساب')
                ->required()
                ->maxLength(255)
                ->placeholder(match ($fixedType) {
                    AccountType::Bank => 'مثال: البنك الأهلي — جنيه، بنك مصر — دولار',
                    AccountType::Wallet => 'مثال: فودافون كاش، محفظة أورانج، محفظة إلكترونية — USD',
                    default => 'مثال: بنك CIB — الجنيه، خزينة الفرع الرئيسي',
                }),
        ];

        if ($fixedType === null) {
            $definitionFields[] = Select::make('type')
                ->label('نوع الحساب')
                ->options(collect(AccountType::cases())->mapWithKeys(
                    fn (AccountType $t) => [$t->value => $t->label()]
                ))
                ->required()
                ->live()
                ->native(false);
        } else {
            $definitionFields[] = Hidden::make('type')
                ->default($fixedType->value)
                ->required()
                ->dehydrated();
        }

        $definitionFields[] = Select::make('owner_type')
            ->label('ملكية الحساب')
            ->helperText('قيمة ثابتة في النظام: «مالك» أو «مكتب» — لا تُكتب هنا اسم شخص.')
            ->options([
                'owner' => 'مالك (الشركة)',
                'office' => 'مكتب / إداري',
            ])
            ->default('owner')
            ->required()
            ->native(false);

        $shouldLockModule = $lockModuleType || $defaultModule !== 'general';

        if ($shouldLockModule) {
            $definitionFields[] = TextInput::make('module_type_display')
                ->label('وحدة العمل (القسم)')
                ->helperText('يُحدَّد تلقائياً من القسم الذي أنشأت الحساب منه — يظهر في خزينة نفس الوحدة في البرنامج.')
                ->afterStateHydrated(function (TextInput $component, $state, ?Model $record) use ($defaultModule): void {
                    $moduleType = (string) ($record?->module_type ?? $defaultModule);
                    $component->state(AccountModuleDivision::moduleLabel($moduleType));
                })
                ->disabled()
                ->dehydrated(false);

            $definitionFields[] = Hidden::make('module_type')
                ->default($defaultModule)
                ->required()
                ->dehydrated();

            $definitionFields[] = Hidden::make('module')
                ->default(AccountModuleDivision::legacyModuleColumn($defaultModule))
                ->dehydrated();
        } else {
            $definitionFields[] = Select::make('module_type')
                ->label('وحدة العمل (القسم)')
                ->options(AccountModuleDivision::moduleTypeOptions())
                ->default($defaultModule)
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    if (is_string($state) && $state !== '') {
                        $set('module', AccountModuleDivision::legacyModuleColumn($state));
                    }
                })
                ->native(false);

            $definitionFields[] = Hidden::make('module')
                ->default(AccountModuleDivision::legacyModuleColumn($defaultModule))
                ->dehydrated();
        }

        $definitionFields[] = Toggle::make('is_module_vault')
            ->label('خزنة الموديول الرسمية')
            ->helperText('فعّلها إذا كانت هذه هي الخزنة الأساسية التي يستلم منها هذا القسم أمواله.')
            ->default(false);

        $isWalletContext = function ($get) use ($fixedType): bool {
            if ($fixedType === AccountType::Wallet) {
                return true;
            }

            return ($get('type') ?? null) === AccountType::Wallet->value;
        };

        $definitionFields[] = Select::make('wallet_provider')
            ->label('نوع المحفظة')
            ->options(WalletProvider::optionsForSelect())
            ->searchable()
            ->native(false)
            ->visible($isWalletContext)
            ->required($isWalletContext);

        $definitionFields[] = TextInput::make('wallet_number')
            ->label('رقم المحفظة / الهاتف')
            ->helperText('يمكنك إنشاء عدة حسابات من نوع «فودافون كاش» مع رقم مختلف لكل محفظة.')
            ->maxLength(100)
            ->visible($isWalletContext)
            ->required($isWalletContext);

        return $schema
            ->components([
                Tabs::make('accountTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('definition')
                            ->label('تعريف الحساب')
                            ->icon(Heroicon::OutlinedBuildingLibrary)
                            ->schema([
                                Section::make(match ($fixedType) {
                                    AccountType::Bank => 'بيانات الحساب البنكي',
                                    AccountType::Wallet => 'بيانات المحفظة الإلكترونية',
                                    default => 'الاسم والنوع',
                                })
                                    ->description(match ($fixedType) {
                                        AccountType::Bank => 'يُستخدم في واجهة Vue لاختيار الحساب البنكي الذي تدخل إليه أموال التذاكر والتحصيل.',
                                        AccountType::Wallet => 'أضف أكثر من محفظة؛ في التشغيل تختار أي محفظة تُسجَّل بها أموال التذاكر.',
                                        default => 'اختر نوعًا واضحًا: بنك، خزينة، محفظة… يظهر في التقارير والقيود.',
                                    })
                                    ->schema($definitionFields)
                                    ->columns(2),
                            ]),
                        Tab::make('money')
                            ->label('العملة والرصيد')
                            ->icon(Heroicon::OutlinedCurrencyDollar)
                            ->schema([
                                Section::make('العملة')
                                    ->schema([
                                        Select::make('currency')
                                            ->label('العملة')
                                            ->options([
                                                'EGP' => 'جنيه مصري (EGP)',
                                                'SAR' => 'ريال سعودي (SAR)',
                                                'USD' => 'دولار أمريكي (USD)',
                                                'AED' => 'درهم إماراتي (AED)',
                                                'KWD' => 'دينار كويتي (KWD)',
                                            ])
                                            ->default('EGP')
                                            ->required()
                                            ->native(false),
                                    ]),
                                Section::make('الرصيد')
                                    ->description('رصيد افتتاحي عند الإنشاء فقط. بعد الحفظ يتحرّك الرصيد عبر المعاملات والقيود والخزينة (نفس مصدر Laravel API). لا يُفضَّل التعديل اليدوي هنا لتفادي اختلاف الأرصدة عن دفتر الأستاذ.')
                                    ->schema([
                                        TextInput::make('balance')
                                            ->label('الرصيد')
                                            ->numeric()
                                            ->default(0)
                                            ->step(0.01)
                                            ->prefix(fn ($get) => match ($get('currency')) {
                                                'EGP' => 'ج.م',
                                                'SAR' => 'ر.س',
                                                'USD' => '$',
                                                'AED' => 'د.إ',
                                                'KWD' => 'د.ك',
                                                default => '',
                                            })
                                            ->disabledOn('edit'),
                                    ]),
                            ]),
                        Tab::make('status')
                            ->label('الحالة والملاحظات')
                            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                            ->schema([
                                Section::make('تشغيلي')
                                    ->schema([
                                        Toggle::make('is_active')
                                            ->label('حساب نشط')
                                            ->default(true)
                                            ->inline(false),
                                        Textarea::make('notes')
                                            ->label('ملاحظات')
                                            ->rows(4)
                                            ->columnSpanFull()
                                            ->placeholder(match ($fixedType) {
                                                AccountType::Bank => 'رقم IBAN، SWIFT، فرع البنك، جهة الاتصال…',
                                                AccountType::Wallet => 'رقم المحفظة، مزود الخدمة، ملاحظات التحصيل…',
                                                default => 'رقم IBAN، فرع البنك، جهة الاتصال…',
                                            }),
                                    ]),
                            ]),
                    ]),
                Hidden::make('created_by')
                    ->default(fn () => auth()->id())
                    ->dehydrated(),
            ]);
    }

    public static function makeDeactivateAction(): Action
    {
        return Action::make('deactivate')
            ->label('إيقاف التنشيط')
            ->icon('heroicon-o-no-symbol')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('إيقاف تنشيط الحساب')
            ->modalDescription('سيختفي الحساب من قوائم الاختيار في البرنامج مع الإبقاء على سجل الحركات والرصيد.')
            ->visible(fn (Account $record): bool => (bool) $record->is_active)
            ->action(function (Account $record): void {
                $record->update(['is_active' => false]);
                Notification::make()
                    ->title('تم إيقاف الحساب')
                    ->body('الحساب لن يظهر في قوائم الاختيار الجديدة.')
                    ->success()
                    ->send();
            });
    }

    public static function makeSafeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->visible(fn (Account $record): bool => $record->canBeDeleted())
            ->action(function (Account $record, DeleteAction $action): void {
                try {
                    $record->delete();
                    Notification::make()->title('تم حذف الحساب')->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()
                        ->title('تعذّر الحذف')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                    $action->halt();
                }
            });
    }

    public static function makeSafeDeleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->action(function (\Illuminate\Support\Collection $records): void {
                $deleted = 0;
                $blocked = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Account || ! $record->canBeDeleted()) {
                        $blocked++;

                        continue;
                    }

                    try {
                        $record->delete();
                        $deleted++;
                    } catch (\RuntimeException) {
                        $blocked++;
                    }
                }

                if ($deleted > 0) {
                    Notification::make()
                        ->title("تم حذف {$deleted} حساب")
                        ->success()
                        ->send();
                }

                if ($blocked > 0) {
                    Notification::make()
                        ->title('تعذّر حذف بعض الحسابات')
                        ->body('الحسابات التي لها رصيد أو حركات — استخدم «إيقاف التنشيط» بدلاً من الحذف.')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }

    public static function makeRechargeAccountAction(): Action
    {
        return Action::make('recharge')
            ->label('إعادة شحن')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->modalHeading('إعادة شحن الرصيد')
            ->modalDescription('تحويل من حساب داخلي (قيود مدين/دائن)، أو إيداع خارجي يزيد رصيد الحساب فقط.')
            ->visible(function (Model $record): bool {
                if (! $record instanceof Account || ! $record->is_active) {
                    return false;
                }
                $type = $record->type instanceof AccountType
                    ? $record->type
                    : AccountType::tryFrom((string) $record->type);

                return in_array($type, [AccountType::Bank, AccountType::Wallet], true);
            })
            ->schema([
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->step(0.01),
                Toggle::make('external_deposit')
                    ->label('إيداع خارجي')
                    ->helperText('فعّلها إذا لم يُخصَم المبلغ من حساب آخر في النظام (تحصيل من عميل، تحويل بنكي وارد، إلخ).')
                    ->default(false)
                    ->live(),
                Select::make('from_account_id')
                    ->label('من حساب')
                    ->options(function (?Model $record): array {
                        if (! $record instanceof Account) {
                            return [];
                        }

                        return AccountRechargeService::sourceAccountOptions($record);
                    })
                    ->searchable()
                    ->visible(fn (Get $get): bool => ! (bool) $get('external_deposit'))
                    ->required(fn (Get $get): bool => ! (bool) $get('external_deposit')),
                Textarea::make('notes')
                    ->label('ملاحظات')
                    ->rows(2),
            ])
            ->action(function (Model $record, array $data): void {
                if (! $record instanceof Account) {
                    return;
                }
                try {
                    app(AccountRechargeService::class)->recharge($record, [
                        'amount' => $data['amount'],
                        'external_deposit' => (bool) ($data['external_deposit'] ?? false),
                        'from_account_id' => isset($data['from_account_id']) ? (int) $data['from_account_id'] : null,
                        'notes' => $data['notes'] ?? null,
                    ]);
                    Notification::make()
                        ->title('تم تسجيل الشحن')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('تعذّر تنفيذ الشحن')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function makeVaultTransferAction(): Action
    {
        return Action::make('vaultTransfer')
            ->label('تحويل عُهدة')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->modalHeading('تحويل عُهدة بين الخزائن')
            ->modalDescription('استخدم هذا لنقل السيولة بين أقسام الموديولات المختلفة.')
            ->visible(fn (Model $record): bool => $record instanceof Account && $record->is_active)
            ->schema([
                TextInput::make('amount')
                    ->label('المبلغ المراد تحويله')
                    ->numeric()
                    ->required()
                    ->minValue(0.01)
                    ->step(0.01),
                Select::make('to_account_id')
                    ->label('إلى خزنة')
                    ->options(function (Account $record): array {
                        return Account::where('id', '!=', $record->id)
                            ->where('is_active', true)
                            ->where('currency', $record->currency)
                            ->where('type', AccountType::Cashbox->value)
                            ->orWhere('is_module_vault', true)
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->required(),
                Textarea::make('notes')
                    ->label('ملاحظات التحويل')
                    ->placeholder('مثال: تدعيم سيولة قسم الطيران')
                    ->rows(2),
            ])
            ->action(function (Account $record, array $data): void {
                try {
                    $toAccountId = (int) $data['to_account_id'];
                    $amount = (float) $data['amount'];
                    $notes = $data['notes'] ?? '';

                    $toAccount = Account::findOrFail($toAccountId);

                    // Use TransactionService to record a balanced journal transfer
                    app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
                        'amount' => $amount,
                        'from_account_id' => $record->id,
                        'to_account_id' => $toAccountId,
                        'module' => \App\Enums\TransactionModule::Office->value,
                        'notes' => "تحويل عُهدة من [{$record->name}] إلى [{$toAccount->name}]: " . $notes,
                        'created_by' => auth()->id(),
                        'allow_from_negative' => false,
                    ]);

                    Notification::make()
                        ->title('تم التحويل بنجاح')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('فشل التحويل')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function configureTable(Table $table, bool $showTypeColumn, bool $showWalletDetails = false, bool $includeViewAction = false): Table
    {
        $columns = [
            TextColumn::make('id', 'الرقم')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('name', 'اسم الحساب')
                ->searchable()
                ->sortable(),
        ];

        if ($showTypeColumn) {
            $columns[] = TextColumn::make('type', 'النوع')
                ->badge()
                ->formatStateUsing(function ($state): string {
                    if ($state instanceof AccountType) {
                        return $state->label();
                    }

                    return AccountType::tryFrom((string) $state)?->label() ?? '—';
                });
        }

        if ($showWalletDetails) {
            $columns[] = TextColumn::make('wallet_provider')
                ->label('نوع المحفظة')
                ->formatStateUsing(function ($state, Account $record): string {
                    if ($record->type !== AccountType::Wallet) {
                        return '—';
                    }

                    return $record->walletProviderLabel() ?: '—';
                })
                ->searchable()
                ->toggleable();
            $columns[] = TextColumn::make('wallet_number')
                ->label('رقم المحفظة')
                ->searchable()
                ->placeholder('—')
                ->toggleable();
        }

        $columns = array_merge($columns, [
            TextColumn::make('balance', 'الرصيد')
                ->money(fn (Account $record): string => strtolower($record->currency ?? 'egp'))
                ->sortable()
                ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
            TextColumn::make('currency', 'العملة')
                ->badge()
                ->color('info'),
            TextColumn::make('module_type', 'الوحدة')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'general' => 'إدارة عليا',
                    'flights' => 'طيران',
                    'bus' => 'باصات',
                    'hajj_umra' => 'حج وعمرة',
                    'visas' => 'تأشيرات',
                    'fawry' => 'فوري',
                    'tourism' => 'سياحة',
                    'office' => 'مكتب',
                    default => $state ?? '—',
                })
                ->toggleable(),
            \Filament\Tables\Columns\IconColumn::make('is_module_vault')
                ->label('خزنة قسم')
                ->boolean()
                ->toggleable(),
            BadgeColumn::make('is_active', 'الحالة')
                ->colors([
                    true => 'success',
                    false => 'danger',
                ])
                ->formatStateUsing(fn ($state) => $state ? 'نشط' : 'غير نشط'),
            TextColumn::make('created_at', 'تاريخ الإضافة')
                ->dateTime('d/m/Y H:i')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]);

        $filters = [];

        if ($showTypeColumn) {
            $filters[] = SelectFilter::make('type', 'نوع الحساب')
                ->options(collect(AccountType::cases())->mapWithKeys(
                    fn (AccountType $t) => [$t->value => $t->label()]
                ));
        }

        $filters[] = SelectFilter::make('is_active', 'الحالة')
            ->options([
                true => 'نشط',
                false => 'غير نشط',
            ]);

        $filters[] = SelectFilter::make('currency', 'العملة')
            ->options([
                'EGP' => 'جنيه مصري',
                'SAR' => 'ريال سعودي',
                'USD' => 'دولار أمريكي',
                'AED' => 'درهم إماراتي',
                'KWD' => 'دينار كويتي',
            ]);

        if ($showWalletDetails) {
            $filters[] = SelectFilter::make('wallet_provider', 'نوع المحفظة')
                ->options(WalletProvider::optionsForSelect());
        }

        return $table
            ->recordTitleAttribute('name')
            ->columns($columns)
            ->filters($filters)
            ->defaultSort('name', 'asc')
            ->recordActions(array_values(array_filter([
                Action::make('statement')
                    ->label('كشف الحساب')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn (Account $record): string => \App\Filament\Admin\Pages\AccountStatement::getUrl(['accountId' => $record->id])),
                self::makeVaultTransferAction(),
                self::makeRechargeAccountAction(),
                $includeViewAction ? ViewAction::make()->modal(false) : null,
                EditAction::make()->modal(false),
                self::makeDeactivateAction(),
                self::makeSafeDeleteAction(),
            ])))
            ->toolbarActions([
                BulkActionGroup::make([
                    self::makeSafeDeleteBulkAction(),
                ]),
            ]);
    }
}
