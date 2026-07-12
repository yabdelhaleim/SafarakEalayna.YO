<?php

namespace App\Models;

use App\Support\Finance\ModelDeletionGuard;
use App\Support\Finance\ModelProfitMutationGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusTicket extends Model
{
    use HasFactory;
    use SoftDeletes, ModelDeletionGuard, ModelProfitMutationGuard;

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
        // Profit-column guard: `profit` is a derived figure ((selling − purchase) × ticket_count)
        // and is auto-computed by this model's own saving observer. External writes
        // (Filament, tinker, controllers, stray `->save()`) are blocked; the model's
        // own observer is allowed via BusTicket::runProfitMutation() below.
        static::saving(function (BusTicket $ticket): void {
            if (! $ticket->isDirty('profit')) {
                return;
            }
            if (\App\Support\Finance\LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }
            if (app()->runningUnitTests()) {
                return;
            }
            if (BusTicket::isProfitMutationAllowed()) {
                return;
            }
            throw new \RuntimeException(
                'لا يمكن تعديل عمود profit في تذكرة الباص (قديم) مباشرةً. '
                .'يُحسب تلقائياً من purchase_price و selling_price و ticket_count.'
            );
        });

        // Auto-compute observer — canonical authoritative writer of `profit`.
        // Wrapped in BusTicket::runProfitMutation() so the guard above sees
        // isProfitMutationAllowed()=true.
        static::saving(function (self $model): void {
            $purchase = (string) $model->purchase_price;
            $selling = (string) $model->selling_price;
            $ticketCount = max((int) $model->ticket_count, 1);
            BusTicket::runProfitMutation(function () use ($model, $selling, $purchase, $ticketCount): void {
                $model->profit = bcmul(bcsub($selling, $purchase, 2), (string) $ticketCount, 2);
            });
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
