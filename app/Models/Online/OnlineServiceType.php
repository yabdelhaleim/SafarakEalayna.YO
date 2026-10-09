<?php

namespace App\Models\Online;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnlineServiceType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'color',
        'icon',
        'is_active',
        'order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Transactions using this service type's code.
     * The FK column is `service_type_code` (string), not `service_type_id` —
     * the relation joins `online_service_types.code` ↔
     * `online_transactions.service_type_code` (Fawry-style lookup pattern).
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(OnlineTransaction::class, 'service_type_code', 'code');
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
