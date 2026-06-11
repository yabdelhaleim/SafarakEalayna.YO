<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HajjUmraStatus;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\TripSupervisor;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مزود البيانات المرجعية لقائمة Vue (تأتي كلها من Filament).
 */
class HajjUmraReferenceController extends Controller
{
    public function programs(Request $request): JsonResponse
    {
        $type = $request->string('type')->lower()->value();

        $query = Program::with(['executingCompany', 'tripSupervisor', 'accommodationTypeRow', 'meccaHotel', 'medinaHotel'])
            ->where('is_active', true);

        if ($type !== '') {
            $query->whereRaw('LOWER(program_type) = ?', [$type]);
        }

        $items = $query->orderByDesc('departure_date')->get()->map(fn (Program $p) => [
            'id' => $p->id,
            'program_name' => $p->program_name,
            'program_type' => $p->program_type,
            'season' => $p->season,
            'total_nights' => $p->total_nights,
            'mecca_hotel_name' => $p->mecca_hotel_name ?: $p->meccaHotel?->name,
            'mecca_hotel_label' => $p->meccaHotel?->name,
            'mecca_nights' => $p->mecca_nights,
            'medina_hotel_name' => $p->medina_hotel_name ?: $p->medinaHotel?->name,
            'medina_hotel_label' => $p->medinaHotel?->name,
            'medina_nights' => $p->medina_nights,
            'departure_date' => $p->departure_date?->format('Y-m-d'),
            'return_date' => $p->return_date?->format('Y-m-d'),
            'airline' => $p->airline,
            'departure_point' => $p->departure_point,
            'accommodation_type' => $p->accommodation_type,
            'accommodation_type_id' => $p->accommodation_type_id,
            'accommodation_label' => $p->accommodationTypeRow?->name_ar,
            'trip_supervisor' => $p->trip_supervisor,
            'trip_supervisor_id' => $p->trip_supervisor_id,
            'trip_supervisor_label' => $p->tripSupervisor?->full_name,
            'executing_company' => $p->executing_company,
            'executing_company_id' => $p->executing_company_id,
            'executing_company_label' => $p->executingCompany?->name,
            'default_purchase_price' => (float) ($p->default_purchase_price ?? 0),
            'default_selling_price' => (float) ($p->default_selling_price ?? 0),
            'booking_status' => $p->booking_status,
        ]);

        return ApiResponse::success('برامج الحج/العمرة', $items);
    }

    public function visaAgents(): JsonResponse
    {
        $items = VisaAgent::active()->orderBy('company_name')->get()->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->company_name ?: $v->contact_person,
            'company_name' => $v->company_name,
            'contact_person' => $v->contact_person,
            'phone' => $v->phone,
            'email' => $v->email,
            'country' => $v->country,
            'visa_type' => $v->visa_type,
            'default_cost_price' => (float) ($v->default_cost_price ?? 0),
        ]);

        return ApiResponse::success('قائمة وكلاء التأشيرات', $items);
    }

    public function executingCompanies(): JsonResponse
    {
        $items = HajjUmraExecutingCompany::active()->orderBy('name')->get()->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name,
            'license_number' => $v->license_number,
            'phone' => $v->phone,
        ]);

        return ApiResponse::success('قائمة الشركات المنفذة', $items);
    }

    public function tripSupervisors(): JsonResponse
    {
        $items = TripSupervisor::active()->orderBy('full_name')->get()->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->full_name,
            'full_name' => $v->full_name,
            'phone' => $v->phone,
        ]);

        return ApiResponse::success('مشرفو الرحلات', $items);
    }

    public function accommodationTypes(): JsonResponse
    {
        $items = AccommodationType::active()->orderBy('sort_order')->get()->map(fn ($v) => [
            'id' => $v->id,
            'name' => $v->name_ar,
            'code' => $v->code,
            'name_ar' => $v->name_ar,
            'name_en' => $v->name_en,
            'capacity' => $v->capacity,
        ]);

        return ApiResponse::success('أنواع التسكين', $items);
    }

    public function visaDurations(): JsonResponse
    {
        $items = VisaDuration::active()->orderBy('sort_order')->get()->map(fn ($v) => [
            'id' => $v->id,
            'label' => $v->label_ar,
            'days' => $v->months ? $v->months * 30 : null,
            'code' => $v->code,
            'label_ar' => $v->label_ar,
            'label_en' => $v->label_en,
            'months' => $v->months,
            'entry_type' => $v->entry_type,
        ]);

        return ApiResponse::success('مدد التأشيرة', $items);
    }

    public function statuses(): JsonResponse
    {
        return ApiResponse::success('قوائم الحالات', [
            'hajj_umra' => collect(HajjUmraStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
            'visa' => collect(VisaStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
            'visa_types' => collect(VisaType::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'visa_entry_types' => collect(VisaEntryType::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
        ]);
    }
}
