<?php

namespace App\Models\HajjUmra;

use App\Models\Program;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HajjUmraExecutingCompany extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (HajjUmraExecutingCompany $company): void {
            if (! $company->account_id) {
                $account = \App\Models\Account::create([
                    'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى'),
                    'type' => \App\Enums\AccountType::Supplier->value,
                    'currency' => 'EGP',
                    'balance' => 0.00,
                    'is_active' => true,
                    'owner_type' => \App\Models\Account::OWNER_TYPE_OWNER,
                    'module_type' => 'hajj_umra',
                    'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام.',
                    'created_by' => auth()->id() ?? 1,
                ]);
                $company->account_id = $account->id;
            } else {
                $account = $company->account;
                if ($account && $company->isDirty('name')) {
                    $account->update([
                        'name' => 'حساب الشركة المنفذة للحج/العمرة: '.$company->name,
                    ]);
                }
            }
        });
    }

    protected $fillable = [
        'name',
        'license_number',
        'phone',
        'account_id',
        'notes',
        'is_active',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account::class);
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class, 'executing_company_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
