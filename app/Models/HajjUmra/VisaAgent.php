<?php

namespace App\Models\HajjUmra;

use App\Models\VisaBooking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisaAgent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_name',
        'contact_person',
        'phone',
        'email',
        'country',
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

    public function visaDetails(): HasMany
    {
        return $this->hasMany(\App\Models\VisaDetail::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
