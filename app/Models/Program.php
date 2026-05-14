<?php

namespace App\Models;

use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\TripSupervisor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Program $program): void {
            if ($program->accommodation_type_id) {
                $type = $program->relationLoaded('accommodationTypeRow')
                    ? $program->accommodationTypeRow
                    : AccommodationType::query()->find($program->accommodation_type_id);

                if ($type !== null) {
                    $program->accommodation_type = strtoupper((string) $type->code);
                }
            } elseif (! filled($program->accommodation_type)) {
                $program->accommodation_type = null;
            }
        });
    }

    protected $fillable = [
        'program_name',
        'program_type',
        'season',
        'total_nights',
        'accommodation_type',
        'accommodation_type_id',
        'mecca_hotel_name',
        'mecca_hotel_id',
        'mecca_nights',
        'medina_hotel_name',
        'medina_hotel_id',
        'medina_nights',
        'departure_date',
        'return_date',
        'airline',
        'trip_supervisor',
        'trip_supervisor_id',
        'executing_company',
        'executing_company_id',
        'departure_point',
        'booking_status',
        'program_price_tier',
        'default_purchase_price',
        'default_selling_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'is_active' => 'boolean',
            'default_purchase_price' => 'decimal:2',
            'default_selling_price' => 'decimal:2',
            'mecca_nights' => 'integer',
            'medina_nights' => 'integer',
            'total_nights' => 'integer',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HajjUmraBooking::class);
    }

    public function meccaHotel(): BelongsTo
    {
        return $this->belongsTo(\App\Models\HajjUmra\Hotel::class, 'mecca_hotel_id');
    }

    public function medinaHotel(): BelongsTo
    {
        return $this->belongsTo(\App\Models\HajjUmra\Hotel::class, 'medina_hotel_id');
    }

    public function executingCompany(): BelongsTo
    {
        return $this->belongsTo(HajjUmraExecutingCompany::class, 'executing_company_id');
    }

    public function tripSupervisor(): BelongsTo
    {
        return $this->belongsTo(TripSupervisor::class, 'trip_supervisor_id');
    }

    public function accommodationTypeRow(): BelongsTo
    {
        return $this->belongsTo(AccommodationType::class, 'accommodation_type_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
