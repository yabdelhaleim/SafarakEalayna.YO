<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflow extends Model
{
    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'status',
        'action_type',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'notes'
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function approvable()
    {
        return $this->morphTo();
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', \App\Enums\ApprovalStatus::PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', \App\Enums\ApprovalStatus::APPROVED);
    }
}
