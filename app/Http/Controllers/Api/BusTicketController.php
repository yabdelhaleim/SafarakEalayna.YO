<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Bus\StoreBusTicketRequest;
use App\Http\Requests\Bus\UpdateBusTicketRequest;
use App\Http\Resources\BusTicketResource;
use App\Models\BusTicket;
use App\Services\BusTicketService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusTicketController extends Controller
{
    public function __construct(private readonly BusTicketService $service) {}

    public function index(Request $request): JsonResponse
    {
        $records = $this->service->list($request->only([
            'date_from',
            'date_to',
            'payment_method',
            'employee_id',
            'search',
            'per_page',
        ]));

        return ApiResponse::paginated('تم جلب تذاكر الباص بنجاح', BusTicketResource::collection($records), $records);
    }

    public function store(StoreBusTicketRequest $request): JsonResponse
    {
        $record = DB::transaction(fn () => $this->service->create($request->validated()));

        return ApiResponse::success('تم إنشاء تذكرة الباص بنجاح', new BusTicketResource($record->load('employee')), 201);
    }

    public function show(BusTicket $busTicket): JsonResponse
    {
        return ApiResponse::success('تم جلب تذكرة الباص بنجاح', new BusTicketResource($busTicket->load('employee')));
    }

    public function update(UpdateBusTicketRequest $request, BusTicket $busTicket): JsonResponse
    {
        $record = DB::transaction(fn () => $this->service->update($busTicket, $request->validated()));

        return ApiResponse::success('تم تحديث تذكرة الباص بنجاح', new BusTicketResource($record->load('employee')));
    }

    public function destroy(BusTicket $busTicket): JsonResponse
    {
        $this->service->delete($busTicket);

        return ApiResponse::success('تم حذف تذكرة الباص بنجاح');
    }

    public function report(Request $request): JsonResponse
    {
        $type = $request->string('type', 'daily')->toString();
        $data = $type === 'monthly'
            ? $this->service->getMonthlyReport((int) $request->input('year'), (int) $request->input('month'))
            : $this->service->getDailyReport(Carbon::parse($request->input('date', now()->toDateString())));

        return ApiResponse::success('تم جلب تقرير الباص بنجاح', $data);
    }
}
