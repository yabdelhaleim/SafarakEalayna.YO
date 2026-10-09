<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountEntry extends Model
{
    /**
     * ⚠️ IMMUTABLE FINANCIAL RECORD — DO NOT ADD SoftDeletes TRAIT
     *
     * This model represents a canonical, append-only ledger entry.
     * Per system architecture rule (see Flight Module Reversal spec),
     * financial entries are NEVER deleted — neither hard nor soft.
     * To reverse a financial effect, create a new offsetting entry
     * (a reversal Transaction/AccountEntry), never delete or modify
     * the original row.
     *
     * If you're considering adding `deleted_at` or SoftDeletes here,
     * stop and re-read this. The correct pattern is a reversal entry.
     */
    protected $fillable = [
        'account_id',
        'transaction_id',
        'debit',
        'credit',
        'balance_after',
        'notes',  // Phase 3b v3 fix: add 'notes' (was missing → caused NULL notes in writeoff entries)
        // FIN-1 (2026-08-21): opening-balance marker. Auto-set on the
        // paired AccountEntry created by Account::created when the new
        // account is created with a non-zero balance.
        'is_opening',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'is_opening' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
