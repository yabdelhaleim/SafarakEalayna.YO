<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'type',
        'status',
        'reference_type',
        'reference_id',
        'invoice_date',
        'due_date',
        'paid_date',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'due_amount',
        'notes',
        'terms',
        'transaction_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'paid_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'type' => InvoiceType::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', InvoiceStatus::Draft);
    }

    public function scopeSent($query)
    {
        return $query->where('status', InvoiceStatus::Sent);
    }

    public function scopePaid($query)
    {
        return $query->where('status', InvoiceStatus::Paid);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', InvoiceStatus::Overdue)
            ->where('due_date', '<', now());
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('invoice_date', [$from, $to]);
    }

    public function scopeOverdueNotPaid($query)
    {
        return $query->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid])
            ->where('due_date', '<', now());
    }

    // Helper methods
    public function isFullyPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid || $this->due_amount <= 0;
    }

    public function isOverdue(): bool
    {
        return !$this->isFullyPaid() && $this->due_date->isPast();
    }

    public function canBePaid(): bool
    {
        return $this->status->canBePaid();
    }

    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }

    public function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $lastInvoice = self::where('invoice_number', 'like', "{$prefix}-{$date}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$date}-{$newNumber}";
    }
}
