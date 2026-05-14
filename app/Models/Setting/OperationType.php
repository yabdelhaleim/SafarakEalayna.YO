<?php

namespace App\Models\Setting;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

class OperationType extends Model
{
    #[Fillable([
        'code',
        'name_ar',
        'name_en',
        'color',
        'is_active',
        'order',
    ])]

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }
}
