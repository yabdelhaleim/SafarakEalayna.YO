<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tourism Employee Refund audit log — one row per refund attempt.
 *
 * Populated by {@see \App\Services\Finance\RefundAuditLogger}.
 *
 * The actor (`user_id` + `user_name`) is ALWAYS derived from the
 * authenticated backend user — never trusted from the request payload.
 * `user_name` is denormalized so the admin view does not break if the
 * user row is later deleted or renamed.
 */
class RefundAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_name',
        'module',
        'booking_id',
        'booking_reference',
        'customer_id',
        'customer_name',
        'refund_amount',
        'currency',
        'paid_amount_before',
        'previously_refunded',
        'remaining_refundable',
        'reason',
        'transaction_id',
        'account_entry_ids',
        'affected_account_id',
        'idempotency_key',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'paid_amount_before' => 'decimal:2',
        'previously_refunded' => 'decimal:2',
        'remaining_refundable' => 'decimal:2',
        'account_entry_ids' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function affectedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'affected_account_id');
    }
}