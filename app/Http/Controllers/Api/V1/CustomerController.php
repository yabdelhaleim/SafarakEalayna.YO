<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'type', 'is_active', 'per_page']);
            $paginator = $this->customerService->getAllCustomers($filters);

            return ApiResponse::paginated(
                'Customers retrieved successfully.',
                CustomerResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        try {
            $customer = $this->customerService->createCustomer($request->validated());

            return ApiResponse::success(
                'Customer created successfully.',
                new CustomerResource($customer),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(Customer $customer): JsonResponse
    {
        try {
            // Load relations on the route model bound instance
            $customer->load(['createdBy']);

            return ApiResponse::success(
                'Customer retrieved successfully.',
                new CustomerResource($customer)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Customer not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $customer = $this->customerService->updateCustomer($customer, $request->validated());

            return ApiResponse::success(
                'Customer updated successfully.',
                new CustomerResource($customer)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(Customer $customer): JsonResponse
    {
        try {
            $this->customerService->deleteCustomer($customer);

            return ApiResponse::success('Customer deleted successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->get('q') ?: $request->get('search');

            if (!$query) {
                return ApiResponse::success('Query is empty.', []);
            }

            $customers = Customer::where('full_name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->limit(10)
                ->get();

            return ApiResponse::success(
                'Customers found.',
                CustomerResource::collection($customers)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * كشف حساب تفصيلي مجمع للعميل أو شركة الكاونتر يشمل كافة الحجوزات والمدفوعات مرتبة زمنياً.
     */
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        try {
            $items = [];
            $totalSales = 0.0;
            $totalPaid = 0.0;

            // 1. Flight Bookings
            $flights = \App\Models\Flight\FlightBooking::with(['flightSystem', 'flightCarrier', 'passengers', 'payments', 'createdBy'])
                ->where('customer_id', $customer->id)
                ->get();

            foreach ($flights as $b) {
                $saleAmount = (float) $b->selling_price;
                $totalSales += $saleAmount;

                $items[] = [
                    'id' => 'FL-' . $b->id,
                    'transaction_id' => 'FL-' . $b->id,
                    'created_at' => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                    'user_name' => $b->createdBy ? $b->createdBy->name : 'النظام',
                    'reference_id' => $b->booking_number ?: $b->booking_reference ?: ('BK-' . $b->id),
                    'entity_name' => $customer->full_name,
                    'description' => 'حجز طيران - خط السير: ' . ($b->route ?: ($b->from_airport . ' → ' . $b->to_airport)),
                    'process_type' => 'فاتورة مبيعات',
                    'module' => 'flight',
                    'credit' => 0.0,
                    'debit' => $saleAmount,
                    'booking_details' => [
                        'pnr' => $b->pnr,
                        'provider_name' => $b->flightSystem ? $b->flightSystem->name : ($b->flightCarrier ? $b->flightCarrier->name : $b->airline_name),
                        'route' => $b->route ?: ($b->from_airport . ' → ' . $b->to_airport),
                        'passengers' => $b->passengers && $b->passengers->count() ? $b->passengers->map(fn($p) => $p->name)->implode('، ') : '—',
                        'status' => $b->status,
                    ],
                ];

                // Payments for Flight
                foreach ($b->payments as $p) {
                    $amt = (float) $p->amount;
                    $totalPaid += $amt;
                    $items[] = [
                        'id' => 'FLP-' . $p->id,
                        'transaction_id' => 'FLP-' . $p->id,
                        'created_at' => $p->created_at ? $p->created_at->toDateTimeString() : now()->toDateTimeString(),
                        'user_name' => 'سداد',
                        'reference_id' => $b->booking_number ?: $b->booking_reference ?: ('BK-' . $b->id),
                        'entity_name' => $customer->full_name,
                        'description' => 'سداد دفعة حجز طيران (' . ($p->payment_method ?: 'نقدي') . ')',
                        'process_type' => 'سداد نقدية',
                        'module' => 'flight',
                        'credit' => $amt,
                        'debit' => 0.0,
                        'booking_details' => null,
                    ];
                }
            }

            // 2. Hajj & Umra Bookings
            $hajj = \App\Models\HajjUmraBooking::with(['program', 'payments', 'createdBy'])
                ->where('customer_id', $customer->id)
                ->get();

            foreach ($hajj as $b) {
                $saleAmount = (float) $b->selling_price;
                $totalSales += $saleAmount;

                $items[] = [
                    'id' => 'HU-' . $b->id,
                    'transaction_id' => 'HU-' . $b->id,
                    'created_at' => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                    'user_name' => $b->createdBy ? $b->createdBy->name : 'النظام',
                    'reference_id' => 'HU-' . $b->id,
                    'entity_name' => $customer->full_name,
                    'description' => 'حجز برنامج: ' . ($b->program ? $b->program->name : 'حج وعمرة'),
                    'process_type' => 'فاتورة مبيعات',
                    'module' => 'hajj_umra',
                    'credit' => 0.0,
                    'debit' => $saleAmount,
                    'booking_details' => [
                        'pnr' => 'HU-' . $b->id,
                        'provider_name' => $b->program ? $b->program->name : '—',
                        'route' => 'رحلة دينية',
                        'passengers' => $customer->full_name,
                        'status' => $b->status instanceof \App\Enums\HajjUmraStatus ? $b->status->label() : $b->status,
                    ],
                ];

                foreach ($b->payments as $p) {
                    $amt = (float) $p->amount;
                    $totalPaid += $amt;
                    $items[] = [
                        'id' => 'HUP-' . $p->id,
                        'transaction_id' => 'HUP-' . $p->id,
                        'created_at' => $p->created_at ? $p->created_at->toDateTimeString() : now()->toDateTimeString(),
                        'user_name' => 'سداد',
                        'reference_id' => 'HU-' . $b->id,
                        'entity_name' => $customer->full_name,
                        'description' => 'سداد دفعة برنامج حج/عمرة',
                        'process_type' => 'سداد نقدية',
                        'module' => 'hajj_umra',
                        'credit' => $amt,
                        'debit' => 0.0,
                        'booking_details' => null,
                    ];
                }
            }

            // 3. Visa Bookings
            $visas = \App\Models\VisaBooking::with(['visaDetail', 'payments', 'createdBy'])
                ->where('customer_id', $customer->id)
                ->get();

            foreach ($visas as $b) {
                $saleAmount = (float) $b->selling_price + (float) ($b->service_fee ?? 0);
                $totalSales += $saleAmount;

                $items[] = [
                    'id' => 'VS-' . $b->id,
                    'transaction_id' => 'VS-' . $b->id,
                    'created_at' => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                    'user_name' => $b->createdBy ? $b->createdBy->name : 'النظام',
                    'reference_id' => 'VS-' . $b->id,
                    'entity_name' => $customer->full_name,
                    'description' => 'إصدار تأشيرة - الوجهة: ' . ($b->visaDetail ? $b->visaDetail->country : 'تأشيرة'),
                    'process_type' => 'فاتورة مبيعات',
                    'module' => 'visa',
                    'credit' => 0.0,
                    'debit' => $saleAmount,
                    'booking_details' => [
                        'pnr' => 'VS-' . $b->id,
                        'provider_name' => $b->visaDetail ? $b->visaDetail->country : '—',
                        'route' => 'تأشيرة سفر',
                        'passengers' => $customer->full_name,
                        'status' => $b->status instanceof \App\Enums\VisaStatus ? $b->status->label() : $b->status,
                    ],
                ];

                foreach ($b->payments as $p) {
                    $amt = (float) $p->amount;
                    $totalPaid += $amt;
                    $items[] = [
                        'id' => 'VSP-' . $p->id,
                        'transaction_id' => 'VSP-' . $p->id,
                        'created_at' => $p->created_at ? $p->created_at->toDateTimeString() : now()->toDateTimeString(),
                        'user_name' => 'سداد',
                        'reference_id' => 'VS-' . $b->id,
                        'entity_name' => $customer->full_name,
                        'description' => 'سداد رسوم تأشيرة',
                        'process_type' => 'سداد نقدية',
                        'module' => 'visa',
                        'credit' => $amt,
                        'debit' => 0.0,
                        'booking_details' => null,
                    ];
                }
            }

            // 4. Bus Bookings
            $buses = \App\Models\Bus\BusBooking::with(['inventory.company', 'payments', 'createdBy'])
                ->where('customer_id', $customer->id)
                ->get();

            foreach ($buses as $b) {
                $saleAmount = (float) $b->total_price;
                $totalSales += $saleAmount;

                $items[] = [
                    'id' => 'BS-' . $b->id,
                    'transaction_id' => 'BS-' . $b->id,
                    'created_at' => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                    'user_name' => $b->createdBy ? $b->createdBy->name : 'النظام',
                    'reference_id' => 'BS-' . $b->id,
                    'entity_name' => $customer->full_name,
                    'description' => 'حجز باص - الجهة: ' . ($b->inventory && $b->inventory->company ? $b->inventory->company->name : 'باص'),
                    'process_type' => 'فاتورة مبيعات',
                    'module' => 'bus',
                    'credit' => 0.0,
                    'debit' => $saleAmount,
                    'booking_details' => [
                        'pnr' => 'BS-' . $b->id,
                        'provider_name' => $b->inventory && $b->inventory->company ? $b->inventory->company->name : '—',
                        'route' => 'نقل بري (' . $b->quantity . ' مقاعد)',
                        'passengers' => $customer->full_name,
                        'status' => $b->status instanceof \App\Enums\BusBookingStatus ? $b->status->label() : $b->status,
                    ],
                ];

                foreach ($b->payments as $p) {
                    $amt = (float) $p->amount;
                    $totalPaid += $amt;
                    $items[] = [
                        'id' => 'BSP-' . $p->id,
                        'transaction_id' => 'BSP-' . $p->id,
                        'created_at' => $p->created_at ? $p->created_at->toDateTimeString() : now()->toDateTimeString(),
                        'user_name' => 'سداد',
                        'reference_id' => 'BS-' . $b->id,
                        'entity_name' => $customer->full_name,
                        'description' => 'سداد تذكرة باص',
                        'process_type' => 'سداد نقدية',
                        'module' => 'bus',
                        'credit' => $amt,
                        'debit' => 0.0,
                        'booking_details' => null,
                    ];
                }
            }

            // 5. Online Transactions
            $onlines = \App\Models\Online\OnlineTransaction::with(['serviceType', 'provider', 'createdBy'])
                ->where('customer_id', $customer->id)
                ->get();

            foreach ($onlines as $b) {
                $saleAmount = (float) $b->selling_price;
                $totalSales += $saleAmount;

                $items[] = [
                    'id' => 'ON-' . $b->id,
                    'transaction_id' => 'ON-' . $b->id,
                    'created_at' => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                    'user_name' => $b->createdBy ? $b->createdBy->name : 'النظام',
                    'reference_id' => $b->reference_number ?: ('ON-' . $b->id),
                    'entity_name' => $customer->full_name,
                    'description' => 'خدمة إلكترونية: ' . ($b->serviceType ? $b->serviceType->name : 'خدمة'),
                    'process_type' => 'فاتورة مبيعات',
                    'module' => 'online',
                    'credit' => 0.0,
                    'debit' => $saleAmount,
                    'booking_details' => [
                        'pnr' => $b->reference_number ?: ('ON-' . $b->id),
                        'provider_name' => $b->provider ? $b->provider->name : '—',
                        'route' => 'أونلاين',
                        'passengers' => $customer->full_name,
                        'status' => $b->status instanceof \App\Enums\OnlineTransactionStatus ? $b->status->label() : $b->status,
                    ],
                ];

                // If completed/paid, mark auto receipt
                if (in_array($b->status instanceof \App\Enums\OnlineTransactionStatus ? $b->status->value : $b->status, ['completed', 'paid'])) {
                    $totalPaid += $saleAmount;
                    $items[] = [
                        'id' => 'ONP-' . $b->id,
                        'transaction_id' => 'ONP-' . $b->id,
                        'created_at' => $b->created_at ? $b->created_at->toDateTimeString() : now()->toDateTimeString(),
                        'user_name' => 'سداد تلقائي',
                        'reference_id' => $b->reference_number ?: ('ON-' . $b->id),
                        'entity_name' => $customer->full_name,
                        'description' => 'تحصيل فوري لخدمة أونلاين',
                        'process_type' => 'سداد نقدية',
                        'module' => 'online',
                        'credit' => $saleAmount,
                        'debit' => 0.0,
                        'booking_details' => null,
                    ];
                }
            }

            // Sort chronologically ascending
            usort($items, function ($a, $b) {
                return strtotime($a['created_at']) <=> strtotime($b['created_at']);
            });

            // Compute line-by-line running balance
            $runningBalance = 0.0;
            foreach ($items as &$item) {
                // Client Debt increases by Sales (debit) and decreases by Payments (credit)
                $runningBalance = $runningBalance + $item['debit'] - $item['credit'];
                $item['balance_after'] = $runningBalance;
                $item['date_human'] = \Carbon\Carbon::parse($item['created_at'])->format('Y-m-d H:i');
            }

            return ApiResponse::success('تم استدعاء كشف حساب العميل التفصيلي بنجاح.', [
                'customer' => new CustomerResource($customer),
                'stats' => [
                    'opening_balance' => 0.0,
                    'period_credit' => $totalPaid, // total paid by client
                    'period_debit' => $totalSales, // total bought by client
                    'closing_balance' => $runningBalance, // net client balance (positive means client owes company)
                ],
                'items' => array_reverse($items), // Reverse to show latest on top in standard datatables
                'pagination' => [
                    'total' => count($items),
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => max(count($items), 20),
                    'from' => count($items) > 0 ? 1 : 0,
                    'to' => count($items),
                ]
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
