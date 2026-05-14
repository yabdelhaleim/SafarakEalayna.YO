<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerReconciliationFinding extends Model
{
    protected $fillable = [
        'ledger_reconciliation_run_id',
        'transaction_id',
        'issue_type',
        'debit_sum',
        'credit_sum',
        'delta',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'debit_sum' => 'decimal:2',
            'credit_sum' => 'decimal:2',
            'delta' => 'decimal:4',
        ];
    }

    public function reconciliationRun(): BelongsTo
    {
        return $this->belongsTo(LedgerReconciliationRun::class, 'ledger_reconciliation_run_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
