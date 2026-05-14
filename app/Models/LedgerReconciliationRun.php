<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerReconciliationRun extends Model
{
    protected $fillable = [
        'run_at',
        'transactions_scanned',
        'imbalanced_count',
        'missing_entries_count',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(LedgerReconciliationFinding::class);
    }
}
