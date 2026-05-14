<?php

namespace App\Models;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisaDetail extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'visa_type',
        'country',
        'duration',
        'visa_duration_id',
        'entry_type',
        'validity_from',
        'validity_to',
        'executing_company',
        'executing_agent',
        'executing_agent_contact',
        'visa_agent_id',
        'submission_date',
        'expected_result_date',
        'visa_number',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'validity_from' => 'date',
            'validity_to' => 'date',
            'submission_date' => 'date',
            'expected_result_date' => 'date',
            'visa_type' => VisaType::class,
            'entry_type' => VisaEntryType::class,
            'status' => VisaStatus::class,
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VisaBooking::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(VisaAgent::class, 'visa_agent_id');
    }

    public function durationRow(): BelongsTo
    {
        return $this->belongsTo(VisaDuration::class, 'visa_duration_id');
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->visa_type instanceof VisaType) {
            $data['visa_type'] = $this->visa_type->value;
            $data['visa_type_label'] = $this->visa_type->label();
        }
        if ($this->entry_type instanceof VisaEntryType) {
            $data['entry_type'] = $this->entry_type->value;
            $data['entry_type_label'] = $this->entry_type->label();
        }
        if ($this->status instanceof VisaStatus) {
            $data['status'] = $this->status->value;
            $data['status_label'] = $this->status->label();
        }
        return $data;
    }
}
