<?php

namespace App\Models\Fawry;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FawryMachineTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'fawry_machine_id',
        'fawry_transaction_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'description',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FawryMachine::class, 'fawry_machine_id');
    }

    public function fawryTransaction(): BelongsTo
    {
        return $this->belongsTo(FawryTransaction::class, 'fawry_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
