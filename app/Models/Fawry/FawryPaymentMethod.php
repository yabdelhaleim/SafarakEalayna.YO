<?php

namespace App\Models\Fawry;

use App\Models\Account;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FawryPaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'color',
        'icon',
        'description_ar',
        'description_en',
        'provider_name',
        'account_number',
        'phone_number',
        'bank_name',
        'branch_name',
        'metadata',
        'default_account_id',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_account_id');
    }

    public function getLabelAttribute(): string
    {
        return $this->name_ar;
    }

    public function getLabelEnAttribute(): string
    {
        return $this->name_en;
    }

    /**
     * الحصول على تفاصيل العرض الكامل (مع البنك ورقم الحساب)
     */
    public function getFullDetailsAttribute(): string
    {
        $parts = [$this->name_ar];

        if ($this->provider_name) {
            $parts[] = $this->provider_name;
        }

        if ($this->bank_name) {
            $parts[] = $this->bank_name;
        }

        if ($this->account_number) {
            $parts[] = "حساب: {$this->account_number}";
        }

        if ($this->phone_number) {
            $parts[] = "رقم: {$this->phone_number}";
        }

        return implode(' - ', array_filter($parts));
    }
}
