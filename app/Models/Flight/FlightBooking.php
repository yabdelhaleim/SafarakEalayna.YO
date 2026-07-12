<?php

namespace App\Models\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\FlightSystemType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FlightPricing;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\ModelDeletionGuard;
use App\Support\Finance\ModelProfitMutationGuard;
use App\Traits\ClearsCache;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'customer_id',
    'employee_id',
    'booking_reference',
    'booking_number',
    'booking_channel_type',
    'booking_channel_provider',
    'system_type',
    'pnr',
    'airline',
    'airline_name',
    'from_airport',
    'from_airport_id',
    'origin',
    'to_airport',
    'to_airport_id',
    'destination',
    'departure_date',
    'return_date',
    'departure_time',
    'arrival_time',
    'return_time',
    'passenger_count',
    'trip_type',
    'trip_details',
    'purchase_price',
    'selling_price',
    'profit',
    'currency',
    'foreign_currency',
    'purchase_price_foreign',
    'exchange_rate',
    'currency_used',
    'balance_currency_used',
    'exchange_rate_used',
    'purchase_price_egp',
    'flight_system_id',
    'flight_carrier_id',
    'flight_group_id',
    'status',
    'account_id',
    'sale_gl_transaction_id',
    'purchase_balance_source',
    'notes',
    'agent_name',
    'baggage_allowance_kg',
    'created_by',
    'original_currency',
    'original_amount',
    'booking_exchange_rate',
    'base_currency_amount',
    'airline_account_id',
])]
class FlightBooking extends Model
{
    use SoftDeletes, ClearsCache, ModelDeletionGuard, ModelProfitMutationGuard;

    protected function casts(): array
    {
        return [
            'booking_channel_type' => \App\Casts\BookingChannelTypeCast::class,
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'profit' => 'decimal:2',
            'currency' => 'string',
            'purchase_price_foreign' => 'decimal:2',
            'exchange_rate' => 'decimal:4',
            'exchange_rate_used' => 'decimal:6',
            'purchase_price_egp' => 'decimal:2',
            'departure_date' => 'date',
            'return_date' => 'date',
            'departure_time' => 'datetime',
            'arrival_time' => 'datetime',
            'return_time' => 'datetime',
            'system_type' => FlightSystemType::class,
            'status' => FlightBookingStatus::class,
            'original_amount' => 'decimal:2',
            'booking_exchange_rate' => 'decimal:6',
            'base_currency_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (FlightBooking $booking) {
            // Allowed only when:
            //   - we're inside FlightBooking::run() (canonical safe path
            //     through FlightBookingService::deleteBookingWithReversal), OR
            //   - the app is running PHPUnit tests (unit/integration tests).
            // Everything else (Filament `DeleteAction`, raw tinker, accidental API
            // calls, etc.) is blocked to prevent silent balance corruption.
            $bypassViaGuard = FlightBooking::isAllowed();

            if (!app()->runningUnitTests() && ! $bypassViaGuard) {
                throw new \RuntimeException('لا يمكن حذف حجز الطيران برمجياً لتجنب إفساد السجلات المالية. يرجى إلغاء الحجز (Cancel) لتسوية الأرصدة تلقائياً.');
            }
        });

        // Profit-column guard: `profit` is a derived figure (selling − purchase)
        // and must be set only via FlightBookingService::createBooking /
        // updateBooking / updatePrices. Wraps both create + update in one place.
        static::saving(function (FlightBooking $booking): void {
            if (! $booking->isDirty('profit')) {
                return;
            }
            if (\App\Support\Finance\LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }
            if (app()->runningUnitTests()) {
                return;
            }
            if (FlightBooking::isAllowed()) {
                return;
            }
            throw new \RuntimeException(
                'لا يمكن تعديل عمود profit في حجز الطيران مباشرةً. '
                .'استخدم FlightBookingService::createBooking / updateBooking / updatePrices.'
            );
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function airlineAccount(): BelongsTo
    {
        return $this->belongsTo(AirlineAccount::class, 'airline_account_id');
    }

    public function flightSystem(): BelongsTo
    {
        return $this->belongsTo(FlightSystem::class, 'flight_system_id');
    }

    public function flightCarrier(): BelongsTo
    {
        return $this->belongsTo(FlightCarrier::class, 'flight_carrier_id');
    }

    public function flightGroup(): BelongsTo
    {
        return $this->belongsTo(FlightGroup::class, 'flight_group_id');
    }

    public function fromAirport(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Airport::class, 'from_airport_id');
    }

    public function toAirport(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Airport::class, 'to_airport_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(FlightPassenger::class, 'flight_booking_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FlightTicket::class, 'flight_booking_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FlightSegment::class, 'flight_booking_id')->orderBy('departure_time');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FlightPayment::class, 'flight_booking_id');
    }

    public function pricing(): HasOne
    {
        return $this->hasOne(FlightPricing::class, 'flight_booking_id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(FlightRefund::class, 'flight_booking_id');
    }

    public function scopeByStatus(Builder $query, FlightBookingStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeByCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeBySystemType(Builder $query, FlightSystemType $type): Builder
    {
        return $query->where('system_type', $type->value);
    }

    public function scopeByDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeByDepartureDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('departure_date', [$from, $to]);
    }

    public function scopeByRoute(Builder $query, string $from, string $to): Builder
    {
        return $query->where('from_airport', $from)->where('to_airport', $to);
    }

    /**
     * حالة تحصيل العميل: paid / partial / unpaid (نفس منطق التصفية في FlightBookingService).
     */
    public function computePaymentStatus(): string
    {
        $selling = (float) $this->selling_price;
        $totalPaid = $this->relationLoaded('payments')
            ? (float) $this->payments->sum(fn ($p) => (float) $p->amount)
            : (float) $this->payments()->sum('amount');

        if ($selling <= 0.01) {
            return 'paid';
        }

        if ($totalPaid >= $selling - 0.01) {
            return 'paid';
        }

        if ($totalPaid > 0.01) {
            return 'partial';
        }

        return 'unpaid';
    }

    public function getPaidAmountAttribute(): float
    {
        return $this->relationLoaded('payments')
            ? (float) $this->payments->sum('amount')
            : (float) $this->payments()->sum('amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return (float) max(0, $this->selling_price - $this->paid_amount);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class, 'flight_booking_id');
    }

    public function airlineCredits(): HasMany
    {
        return $this->hasMany(AirlineCredit::class, 'flight_booking_id');
    }

    public function modifications(): HasMany
    {
        return $this->hasMany(TicketModification::class, 'booking_id');
    }
}
