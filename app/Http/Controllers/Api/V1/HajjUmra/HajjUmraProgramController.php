<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\HajjUmra\StoreProgramRequest;
use App\Http\Requests\HajjUmra\UpdateProgramRequest;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HajjUmraProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Program::with(['executingCompany', 'tripSupervisor', 'accommodationTypeRow', 'meccaHotel', 'medinaHotel'])
            ->orderByDesc('departure_date');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($type = $request->string('type')->lower()->value()) {
            $query->whereRaw('LOWER(program_type) = ?', [$type]);
        }

        $items = $query->get()->map(fn (Program $program) => $this->formatProgram($program));

        return ApiResponse::success('برامج الحج والعمرة', $items);
    }

    public function show(Program $program): JsonResponse
    {
        $program->load(['executingCompany', 'tripSupervisor', 'accommodationTypeRow', 'meccaHotel', 'medinaHotel']);

        return ApiResponse::success('تفاصيل البرنامج', $this->formatProgram($program));
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $payload = array_merge([
            'booking_status' => 'open',
            'is_active' => true,
        ], $request->validated());

        if (empty($payload['accommodation_type'])) {
            $payload['accommodation_type'] = 'QUAD';
        }

        $program = Program::create($payload);

        $program->load(['executingCompany', 'tripSupervisor', 'accommodationTypeRow', 'meccaHotel', 'medinaHotel']);

        return ApiResponse::success('تم إنشاء البرنامج', $this->formatProgram($program), 201);
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $program->update($request->validated());
        $program->load(['executingCompany', 'tripSupervisor', 'accommodationTypeRow', 'meccaHotel', 'medinaHotel']);

        return ApiResponse::success('تم تحديث البرنامج', $this->formatProgram($program));
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatProgram(Program $program): array
    {
        return [
            'id' => $program->id,
            'program_name' => $program->program_name,
            'program_type' => $program->program_type,
            'season' => $program->season,
            'total_nights' => $program->total_nights,
            'mecca_hotel_name' => $program->mecca_hotel_name ?: $program->meccaHotel?->name,
            'mecca_hotel_label' => $program->meccaHotel?->name,
            'mecca_hotel_id' => $program->mecca_hotel_id,
            'mecca_nights' => $program->mecca_nights,
            'medina_hotel_name' => $program->medina_hotel_name ?: $program->medinaHotel?->name,
            'medina_hotel_label' => $program->medinaHotel?->name,
            'medina_hotel_id' => $program->medina_hotel_id,
            'medina_nights' => $program->medina_nights,
            'departure_date' => $program->departure_date?->format('Y-m-d'),
            'return_date' => $program->return_date?->format('Y-m-d'),
            'airline' => $program->airline,
            'departure_point' => $program->departure_point,
            'accommodation_type' => $program->accommodation_type,
            'accommodation_type_id' => $program->accommodation_type_id,
            'accommodation_label' => $program->accommodationTypeRow?->name_ar,
            'trip_supervisor' => $program->trip_supervisor,
            'trip_supervisor_id' => $program->trip_supervisor_id,
            'trip_supervisor_label' => $program->tripSupervisor?->full_name,
            'executing_company' => $program->executing_company,
            'executing_company_id' => $program->executing_company_id,
            'executing_company_label' => $program->executingCompany?->name,
            'default_purchase_price' => (float) ($program->default_purchase_price ?? 0),
            'default_selling_price' => (float) ($program->default_selling_price ?? 0),
            'booking_status' => $program->booking_status,
            'program_price_tier' => $program->program_price_tier,
            'is_active' => (bool) $program->is_active,
        ];
    }
}
