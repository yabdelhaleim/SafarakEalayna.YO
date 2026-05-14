<?php

namespace App\Models\Bus;

use App\Models\User;
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
    use SoftDeletes;

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
        static::creating(function (BusCompany $company) {
            if (!$company->account_id) {
                $account = \App\Models\Account::create([
                    'name' => 'حساب شركة: ' . $company->name,
                    'type' => \App\Enums\AccountType::Treasury,
                    'module_type' => 'bus',
                    'currency' => 'EGP',
                    'is_active' => true,
                    'created_by' => $company->created_by ?? \Illuminate\Support\Facades\Auth::id(),
                ]);
                $company->account_id = $account->id;
            }
        });

        static::deleting(function (BusCompany $company) {
            // Optional: Handle account deletion or deactivation if needed
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
