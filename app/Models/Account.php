<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Support\Finance\LedgerBalanceMutationGuard;
use BackedEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use HasFactory;

    public const OWNER_TYPE_OWNER = 'owner';

    public const OWNER_TYPE_OFFICE = 'office';

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

            $type = $account->type instanceof AccountType
                ? $account->type
                : AccountType::tryFrom((string) $account->type);

            if ($type !== AccountType::Wallet) {
                $account->wallet_provider = null;
                $account->wallet_number = null;
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

    public function scopeTourism($query)
    {
        return $query->where('module_type', 'tourism');
    }

    public function scopeOffice($query)
    {
        return $query->where('module_type', 'office');
    }

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
        if (in_array($this->type, [AccountType::Cashbox, AccountType::Bank, AccountType::Wallet, AccountType::Treasury], true)) {
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
