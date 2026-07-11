<?php

namespace App\Models;

use App\Support\Finance\ModelDeletionGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusTicket extends Model
{
    use HasFactory;
    use SoftDeletes, ModelDeletionGuard;

    protected $fillable = [
        'passenger_name',
        'phone',
        'country',
        'bus_name',
        'ticket_count',
        'from_city',
        'to_city',
        'departure_date',
        'departure_time',
        'return_date',
        'return_time',
        'purchase_price',
        'selling_price',
        'profit',
        'employee_id',
        'payment_method',
        'amount',
        'reference_number',
        'notes',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'return_date' => 'date',
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $purchase = (string) $model->purchase_price;
            $selling = (string) $model->selling_price;
            $ticketCount = max((int) $model->ticket_count, 1);
            $model->profit = bcmul(bcsub($selling, $purchase, 2), (string) $ticketCount, 2);
        });

        // Allowed only when:
        //   - we're inside BusTicket::run() (canonical safe path through
        //     BusTicketService::delete()), OR
        //   - the app is running PHPUnit tests (unit/integration tests).
        // Everything else (Filament `DeleteAction`, raw tinker, accidental API
        // calls, etc.) is blocked to prevent accidental loss of legacy ticket
        // records that are referenced by old receipts / audit logs.
        static::deleting(function (BusTicket $ticket) {
            $bypassViaGuard = BusTicket::isAllowed();

            if (! app()->runningUnitTests() && ! $bypassViaGuard) {
                throw new \RuntimeException(
                    'لا يمكن حذف تذكرة الباص (قديم) برمجياً. '
                    .'يرجى استخدام BusTicketService::delete() للحذف الإداري المعتمد.'
                );
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
