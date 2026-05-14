<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FawryTransaction extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'client_name',
        'operation_type',
        'client_amount',
        'fawry_price',
        'selling_price',
        'profit',
        'employee_id',
        'payment_method',
        'amount',
        'reference_number',
        'notes',
    ];

    protected $casts = [
        'client_amount' => 'decimal:2',
        'fawry_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $model->profit = bcsub((string) $model->selling_price, (string) $model->fawry_price, 2);
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
