<?php

namespace App\Models\Bus;

use App\Models\User;
use App\Support\Finance\ModelDeletionGuard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'phone',
    'account_id',
    'address',
    'is_active',
    'notes',
    'created_by',
])]
class BusCompany extends Model
{
    use SoftDeletes, ModelDeletionGuard;

    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Allowed only when:
        //   - we're inside BusCompany::run() (canonical safe path through
        //     BusCompanyService::deleteCompany()), OR
        //   - the app is running PHPUnit tests (unit/integration tests).
        // Everything else (Filament `DeleteAction`, raw tinker, accidental API
        // calls, etc.) is blocked to prevent silent balance corruption.
        static::deleting(function (BusCompany $company) {
            $bypassViaGuard = BusCompany::isAllowed();

            if (! app()->runningUnitTests() && ! $bypassViaGuard) {
                throw new \RuntimeException(
                    'لا يمكن حذف شركة الباص برمجياً لتجنب إفساد السجلات المالية. '
                    .'يرجى استخدام BusCompanyService::deleteCompany() للحذف الإداري المعتمد.'
                );
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(BusInventory::class, 'company_id');
    }

    public function companyPayments(): HasMany
    {
        return $this->hasMany(BusCompanyPayment::class, 'company_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
