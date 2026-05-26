<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InvoiceService;
use App\Http\Resources\Invoice\InvoiceResource;
use App\Http\Resources\Invoice\InvoicePaymentResource;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Invoice::class);

        $filters = [
            'customer_id' => $request->customer_id,
            'status' => $request->status,
            'type' => $request->type,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'search' => $request->search,
        ];

        $invoices = $this->invoiceService->getAllInvoices($filters)
            ->paginate(min($request->per_page ?? 15, 100));

        return ApiResponse::success(
            'Invoices retrieved successfully',
            InvoiceResource::collection($invoices)->response()->getData(true),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', \App\Models\Invoice::class);

        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'type' => 'nullable|in:flight,bus,service,online,hajj_umrah,visa,general',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date|after:invoice_date',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
            'terms' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.details' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.item_type' => 'nullable|string',
            'items.*.item_id' => 'nullable|integer',
        ]);

        $invoice = $this->invoiceService->createInvoice($request->all());

        return ApiResponse::success(
            'Invoice created successfully',
            new InvoiceResource($invoice->load('customer')),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $invoice = $this->invoiceService->getInvoiceById($id);

        $this->authorize('view', $invoice);

        return ApiResponse::success(
            'Invoice retrieved successfully',
            new InvoiceResource($invoice),
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = \App\Models\Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        $request->validate([
            'customer_id' => 'sometimes|required|exists:customers,id',
            'type' => 'nullable|in:flight,bus,service,online,hajj_umrah,visa,general',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date|after:invoice_date',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
            'terms' => 'nullable|string|max:1000',
            'items' => 'nullable|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.details' => 'nullable|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.item_type' => 'nullable|string',
            'items.*.item_id' => 'nullable|integer',
        ]);

        $invoice = $this->invoiceService->updateInvoice($invoice, $request->all());

        return ApiResponse::success(
            'Invoice updated successfully',
            new InvoiceResource($invoice->load('customer')),
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $invoice = \App\Models\Invoice::findOrFail($id);

        $this->authorize('delete', $invoice);

        $this->invoiceService->deleteInvoice($invoice);

        return ApiResponse::success('Invoice deleted successfully');
    }

    public function send(int $id): JsonResponse
    {
        $invoice = \App\Models\Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        $invoice = $this->invoiceService->sendInvoice($invoice);

        return ApiResponse::success(
            'Invoice sent successfully',
            new InvoiceResource($invoice),
        );
    }

    public function cancel(int $id): JsonResponse
    {
        $invoice = \App\Models\Invoice::findOrFail($id);

        $this->authorize('update', $invoice);

        $invoice = $this->invoiceService->cancelInvoice($invoice);

        return ApiResponse::success(
            'Invoice cancelled successfully',
            new InvoiceResource($invoice),
        );
    }

    public function addPayment(Request $request, int $id): JsonResponse
    {
        $this->authorize('update', \App\Models\Invoice::class);

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|in:cash,bank_transfer,credit_card,check,other',
            'reference_number' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'account_id' => 'nullable|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment = $this->invoiceService->addPayment($id, $request->all());

        return ApiResponse::success(
            'Payment added successfully',
            new InvoicePaymentResource($payment),
            201
        );
    }

    public function getPayments(int $id): JsonResponse
    {
        $this->authorize('view', \App\Models\Invoice::class);

        $invoice = $this->invoiceService->getInvoiceById($id);

        return ApiResponse::success(
            'Payments retrieved successfully',
            InvoicePaymentResource::collection($invoice->payments),
        );
    }

    public function getStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Invoice::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $stats = $this->invoiceService->getInvoiceStats(
            $request->from_date,
            $request->to_date
        );

        return ApiResponse::success(
            'Invoice statistics retrieved successfully',
            $stats,
        );
    }
}
