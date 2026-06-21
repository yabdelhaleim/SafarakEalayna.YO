<?php

namespace App\Models;

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use HasFactory;
    protected $fillable = [
        'type',
        'amount',
        'currency',
        'module',
        'related_type',
        'related_id',
        'program_id',
        'from_account_id',
        'to_account_id',
        'created_by',
        'notes',
        'attachment_path',
        'posting_channel',
        'correlation_id',
        'http_method',
        'request_route',
        'client_ip',
        'user_agent',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'module' => TransactionModule::class,
        'amount' => 'decimal:2',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany('App\Models\AccountEntry');
    }

    public function transfer()
    {
        return $this->hasOne('App\Models\Transfer');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeByType($query, TransactionType $type)
    {
        return $query->where('type', $type->value);
    }

    public function scopeByModule($query, TransactionModule $module)
    {
        return $query->where('module', $module->value);
    }

    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId);
    }

    public function scopeByDateRange($query, ?string $from = null, ?string $to = null)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
    }
}
