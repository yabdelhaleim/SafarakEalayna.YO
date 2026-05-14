<?php

namespace App\Models\HajjUmra;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VisaDuration extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'label_ar',
        'label_en',
        'months',
        'entry_type',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'months' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
