<?php

namespace App\Models\Online;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnlineServiceProvider extends Model
{
    use SoftDeletes;

    protected $table = 'online_service_providers';

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'color',
        'icon',
        'contact_phone',
        'contact_account',
        'metadata',
        'default_purchase_account_id',
        'is_active',
        'order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function defaultPurchaseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_purchase_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OnlineTransaction::class, 'provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    public function getNameAttribute(): string
    {
        return $this->name_ar ?? '';
    }
}
