<?php

namespace App\Models\Setting;

use Illuminate\Database\Eloquent\Model;

class PrintSetting extends Model
{
    protected $fillable = [
        'company_name_ar',
        'company_name_en',
        'address',
        'phones',
        'finance_label',
        'show_amount_due',
        'modules',
    ];

    protected function casts(): array
    {
        return [
            'show_amount_due' => 'boolean',
            'modules' => 'array',
        ];
    }
}
