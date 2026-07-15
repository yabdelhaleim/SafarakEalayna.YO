<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use BackedEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Traits\ClearsCache;

class Account extends Model
{
    use HasFactory, ClearsCache;

    public const OWNER_TYPE_OWNER = 'owner';

    public const OWNER_TYPE_OFFICE = 'office';

    /**
     * Mass-assignable attributes.
     *
     * Module classification fields:
     *  - `module_type` — strict classification column. Must be one of
     *    {@see \App\Support\Finance\AccountModuleContract::OFFICE_MODULE_TYPE},
     *    {@see \App\Support\Finance\AccountModuleContract::TOURISM_MODULE_TYPE},
     *    or a specific module inside the appropriate division.
     *  - `module` — optional preferred-label / alias column. `null` means
     *    the account is truly shared across all modules of its division.
     *    A non-null value narrows the display label but does NOT change
     *    the division membership.
     *
     * See {@see \App\Support\Finance\AccountModuleContract} for the
     * division / alias contract that this project commits to.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'balance',
        'currency',
        'is_active',
        'owner_type',
        'module_type',
        'module',
        'is_module_vault',
        'notes',
        'created_by',
        'wallet_provider',
        'wallet_number',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_module_vault' => 'boolean',
        'wallet_provider' => WalletProvider::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (Account $account): void {
            if (! $account->isDirty('balance')) {
                return;
            }

            if (! config('accounting.balance_guard.block_unauthorized_updates', true)) {
                return;
            }

            if (config('accounting.balance_guard.disable_in_testing', false) && app()->runningUnitTests()) {
                return;
            }

            if (LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }

            throw new \RuntimeException(
                'تعديل رصيد الحساب مباشرةً غير مسموح. استخدم مسار الدفتر (قيود المعاملة) عبر خدمات المالية المعتمدة.'
            );
        });

        static::saving(function (Account $account): void {
            $allowed = [self::OWNER_TYPE_OWNER, self::OWNER_TYPE_OFFICE];
            $v = $account->owner_type;
            if (! is_string($v) || ! in_array($v, $allowed, true)) {
                $account->owner_type = self::OWNER_TYPE_OWNER;
            }

            // ⚠️ Phase 4 fix: استخدام ->value بدل (string) cast
            // السبب: في PHP 8.3.31 على الـ VPS، الـ BackedEnum::__toString() مش شغّال
            //        (instanceof Stringable = NO). الـ ->value property شغّال 100%.
            $typeValue = $account->type instanceof AccountType
                ? $account->type->value
                : (is_string($account->type) ? $account->type : null);
            $type = $typeValue !== null ? AccountType::tryFrom($typeValue) : null;

            if ($type !== AccountType::Wallet) {
                $account->wallet_provider = null;
                $account->wallet_number = null;
            }

            // Phase 3 + Phase 3.5: enforce the office/tourism division contract
            // for `module_type` based on the AccountType.
            //
            // ─────────────────────────────────────────────────────────────────
            // RULE (dual-meaning)
            //   Liquidity accounts (cashbox / wallet / bank)
            //     → module_type MUST be a DIVISION ('office' or 'tourism').
            //       They are unified within a division; the pool appears in
            //       every module's dropdown under that division (Phase 6).
            //
            //   Subject accounts (customer / supplier)
            //     → module_type MUST be a SPECIFIC module (bus, fawry, online,
            //       wallet_transfer, flights, hajj_umra, visas). Customers of
            //       one module are tracked separately from customers of another
            //       module even when both sit under the same division.
            //
            //   Internal accounts (expense / revenue / liability / owner)
            //     → constraint enforced at config level (clearing rows); the
            //       hook does not over-restrict here.
            // ─────────────────────────────────────────────────────────────────
            $moduleType = $account->module_type instanceof \BackedEnum
                ? $account->module_type->value
                : (string) ($account->module_type ?? '');
            $moduleTypeLower = strtolower($moduleType);

            $isLiquidity = $type !== null
                && in_array($typeValue, AccountModuleContract::LIQUIDITY_TYPES, true);
            $isSubject = $type !== null
                && in_array($typeValue, AccountModuleContract::SUBJECT_TYPES, true);

            if ($isLiquidity) {
                $validDivisions = [
                    AccountModuleContract::OFFICE_MODULE_TYPE,
                    AccountModuleContract::TOURISM_MODULE_TYPE,
                ];
                if (! in_array($moduleTypeLower, $validDivisions, true)) {
                    throw new \InvalidArgumentException(
                        'Liquidity accounts ('
                        . implode('/', AccountModuleContract::LIQUIDITY_TYPES)
                        . ') require module_type to be a DIVISION — got "' . $moduleTypeLower . '"'
                        . '. Use "' . AccountModuleContract::OFFICE_MODULE_TYPE
                        . '" (bus/fawry/online/wallet_transfer) or "'
                        . AccountModuleContract::TOURISM_MODULE_TYPE
                        . '" (flights/hajj_umra/visas). See '
                        . 'App\\Support\\Finance\\AccountModuleContract for the contract.'
                    );
                }
            } elseif ($isSubject) {
                $reservedForLiquidity = [
                    AccountModuleContract::OFFICE_MODULE_TYPE,
                    AccountModuleContract::TOURISM_MODULE_TYPE,
                ];
                $allowedModules = array_merge(
                    AccountModuleContract::OFFICE_DIVISION_MODULES,
                    AccountModuleContract::TOURISM_DIVISION_MODULES
                );
                $allowedModules = array_diff($allowedModules, $reservedForLiquidity);
                $allowedModules = array_values(array_unique($allowedModules));

                if ($moduleTypeLower === ''
                    || in_array($moduleTypeLower, $reservedForLiquidity, true)
                    || ! in_array($moduleTypeLower, $allowedModules, true)
                ) {
                    throw new \InvalidArgumentException(
                        'Subject accounts ('
                        . implode('/', AccountModuleContract::SUBJECT_TYPES)
                        . ') require module_type to be a SPECIFIC module — got "' . $moduleTypeLower . '"'
                        . '. Use one of: ' . implode(', ', $allowedModules)
                        . '. The division names "'
                        . AccountModuleContract::OFFICE_MODULE_TYPE . '" and "'
                        . AccountModuleContract::TOURISM_MODULE_TYPE
                        . '" are RESERVED for liquidity vaults. See '
                        . 'App\\Support\\Finance\\AccountModuleContract for the contract.'
                    );
                }
            }

            if ($account->module_type && ! $account->module) {
                $account->module = $account->module_type instanceof BackedEnum
                    ? $account->module_type->value
                    : $account->module_type;
            }
        });

        static::deleting(function (Account $account): void {
            if (! $account->canBeDeleted()) {
                throw new \RuntimeException('لا يمكن حذف حساب مالي يحتوي على حركات أو رصيد. يرجى إيقاف تنشيطه بدلاً من ذلك.');
            }
        });
    }

    public function canBeDeleted(): bool
    {
        return (float) $this->balance === 0.0 && ! $this->entries()->exists();
    }

    public function walletProviderLabel(): string
    {
        if ($this->wallet_provider instanceof WalletProvider) {
            return $this->wallet_provider->label();
        }

        return WalletProvider::tryFrom((string) $this->wallet_provider)?->label()
            ?? (string) ($this->wallet_provider ?? '');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class, 'account_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany('App\Models\AccountEntry');
    }

    public function outgoingTransactions(): HasMany
    {
        return $this->hasMany('App\Models\Transaction', 'from_account_id');
    }

    public function incomingTransactions(): HasMany
    {
        return $this->hasMany('App\Models\Transaction', 'to_account_id');
    }

    public function fawryTransactions(): HasMany
    {
        return $this->hasMany(\App\Models\Fawry\FawryTransaction::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, AccountType $type)
    {
        return $query->where('type', $type->value);
    }

    public function scopeOwner($query, $ownerType)
    {
        return $query->where('owner_type', $ownerType);
    }

    public function scopeCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    public function scopeForOwner($query, $ownerType)
    {
        return $query->where('owner_type', $ownerType);
    }

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->whereHas('supplier', function ($q) use ($supplierId) {
            $q->where('id', $supplierId);
        });
    }

    /**
     * Filter accounts belonging to the Tourism division
     * (flights, hajj_umra, visas). Uses `module_type` STRICTLY.
     *
     * For division-aware matching via the `module` alias column, see
     * {@see \App\Support\Finance\AccountModuleContract::divisionFor()}.
     *
     * NOTE: This scope intentionally uses the legacy
     * `AccountModuleDivision::TOURISM` array (which currently equals the
     * contract's `TOURISM_DIVISION_MODULES`). Future versions may swap
     * the source to the Contract class without behavior change.
     */
    public function scopeTourism($query)
    {
        return $query->whereIn('module_type', \App\Support\Finance\AccountModuleDivision::TOURISM);
    }

    /**
     * Filter accounts belonging to the Office division
     * (bus, fawry, online, wallet_transfer). Uses `module_type` STRICTLY.
     *
     * For division-aware matching via the `module` alias column, see
     * {@see \App\Support\Finance\AccountModuleContract::divisionFor()}.
     *
     * NOTE: This scope intentionally uses the legacy
     * `AccountModuleDivision::OFFICE` array (which still contains a
     * legacy `'general'` entry for backward compatibility with pre-2026
     * code). The Contract class does NOT include `'general'`.
     */
    public function scopeOffice($query)
    {
        return $query->whereIn('module_type', \App\Support\Finance\AccountModuleDivision::OFFICE);
    }

    /**
     * Filter accounts by the `module` column (the per-account preferred label).
     *
     * IMPORTANT: This uses the `module` column, NOT `module_type`. Use this
     * scope when matching an account by its preferred-label alias only
     * (e.g. for display or when the alias is explicitly known).
     *
     * For division-aware matching (the common case during unification),
     * prefer {@see \App\Support\Finance\AccountModuleContract::divisionFor()}
     * combined with a `whereIn('module_type', [...])` rather than this scope.
     *
     * @see \App\Support\Finance\AccountModuleContract
     */
    public function scopeModule($query, $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Determine the payment status for this account.
     * For supplier accounts, it checks the debt status.
     * For treasury accounts, it's usually N/A or based on reconciliation.
     */
    public function getPaymentStatusAttribute(): string
    {
        if (in_array($this->type, [AccountType::Cashbox, AccountType::Bank, AccountType::Wallet, AccountType::Bank], true)) {
            return $this->balance < 0 ? 'partial' : 'paid';
        }

        // For Debt/Entity Accounts (which should mostly be filtered out but just in case)
        if ($this->balance == 0) {
            return 'unpaid';
        }

        return $this->balance > 0 ? 'paid' : 'partial';
    }

    public static function getModuleVault(string $moduleType): ?Account
    {
        return self::where('module_type', $moduleType)
            ->where('is_module_vault', true)
            ->where('is_active', true)
            ->first();
    }
}
