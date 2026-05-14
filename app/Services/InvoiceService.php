<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Customer;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Services\Finance\TransactionService;
use App\Services\Finance\AccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    protected TransactionService $transactionService;
    protected AccountService $accountService;

    public function __construct(
        TransactionService $transactionService,
        AccountService $accountService
    ) {
        $this->transactionService = $transactionService;
        $this->accountService = $accountService;
    }

    public function getAllInvoices(array $filters = null)
    {
        $query = Invoice::with(['customer', 'items', 'payments']);

        if ($filters) {
            if (isset($filters['customer_id'])) {
                $query->where('customer_id', $filters['customer_id']);
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['from_date']) && isset($filters['to_date'])) {
                $query->whereBetween('invoice_date', [$filters['from_date'], $filters['to_date']]);
            }

            if (isset($filters['search'])) {
                $query->where('invoice_number', 'like', "%{$filters['search']}%");
            }
        }

        return $query->orderBy('invoice_date', 'desc');
    }

    public function getInvoiceById(int $id): Invoice
    {
        return Invoice::with(['customer', 'items', 'payments', 'createdBy'])->findOrFail($id);
    }

    public function createInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            // Generate invoice number
            $invoice = new Invoice([
                'invoice_number' => $this->generateInvoiceNumber(),
                'customer_id' => $data['customer_id'],
                'type' => $data['type'] ?? InvoiceType::General->value,
                'status' => InvoiceStatus::Draft,
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $invoice->save();

            // Add items
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $this->addInvoiceItem($invoice, $item);
                }
            }

            // Recalculate totals
            $this->recalculateInvoiceTotals($invoice);

            Log::info('Invoice created', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
            ]);

            return $invoice->fresh(['items']);
        });
    }

    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            // Can only update draft invoices
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new \Exception('Can only update draft invoices');
            }

            $fillable = [
                'customer_id', 'type', 'invoice_date', 'due_date',
                'notes', 'terms', 'reference_type', 'reference_id'
            ];

            foreach ($fillable as $field) {
                if (isset($data[$field])) {
                    $invoice->$field = $data[$field];
                }
            }

            $invoice->save();

            // Update items if provided
            if (isset($data['items']) && is_array($data['items'])) {
                // Delete existing items
                $invoice->items()->delete();

                // Add new items
                foreach ($data['items'] as $item) {
                    $this->addInvoiceItem($invoice, $item);
                }
            }

            // Recalculate totals
            $this->recalculateInvoiceTotals($invoice);

            Log::info('Invoice updated', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice->fresh(['items']);
        });
    }

    public function deleteInvoice(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            if (!$invoice->canBeCancelled()) {
                throw new \Exception('Cannot delete this invoice');
            }

            $invoice->delete();

            Log::info('Invoice deleted', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return true;
        });
    }

    public function sendInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new \Exception('Invoice must be in draft status');
            }

            $invoice->status = InvoiceStatus::Sent;
            $invoice->save();

            Log::info('Invoice sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice->fresh();
        });
    }

    public function addPayment(int $invoiceId, array $data): InvoicePayment
    {
        return DB::transaction(function () use ($invoiceId, $data) {
            $invoice = Invoice::findOrFail($invoiceId);

            if (!$invoice->canBePaid()) {
                throw new \Exception('This invoice cannot be paid');
            }

            $payment = InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'reference_number' => $data['reference_number'] ?? null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Create accounting transaction if account provided
            if (isset($data['account_id'])) {
                $transaction = $this->transactionService->recordTransaction([
                    'type' => 'income',
                    'module' => 'general',
                    'amount' => $data['amount'],
                    'description' => "Invoice payment: {$invoice->invoice_number}",
                    'account_id' => $data['account_id'],
                    'reference_type' => 'invoice_payment',
                    'reference_id' => $payment->id,
                ]);

                $payment->transaction_id = $transaction->id;
                $payment->account_id = $data['account_id'];
                $payment->save();
            }

            // Update invoice status and amounts
            $this->updateInvoicePaymentStatus($invoice);

            Log::info('Invoice payment added', [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'amount' => $data['amount'],
            ]);

            return $payment->fresh();
        });
    }

    public function cancelInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            if (!$invoice->canBeCancelled()) {
                throw new \Exception('This invoice cannot be cancelled');
            }

            $invoice->status = InvoiceStatus::Cancelled;
            $invoice->save();

            Log::info('Invoice cancelled', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice->fresh();
        });
    }

    public function markOverdueInvoices(): int
    {
        $overdueInvoices = Invoice::whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::PartiallyPaid])
            ->where('due_date', '<', now()->toDateString())
            ->get();

        $count = 0;
        foreach ($overdueInvoices as $invoice) {
            $invoice->status = InvoiceStatus::Overdue;
            $invoice->save();
            $count++;
        }

        if ($count > 0) {
            Log::info('Marked invoices as overdue', ['count' => $count]);
        }

        return $count;
    }

    protected function addInvoiceItem(Invoice $invoice, array $item): InvoiceItem
    {
        $taxAmount = ($item['quantity'] * $item['unit_price'] * ($item['tax_rate'] ?? 0)) / 100;
        $total = ($item['quantity'] * $item['unit_price']) + $taxAmount - ($item['discount_amount'] ?? 0);

        return $invoice->items()->create([
            'description' => $item['description'],
            'details' => $item['details'] ?? null,
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'tax_rate' => $item['tax_rate'] ?? 0,
            'tax_amount' => $taxAmount,
            'discount_amount' => $item['discount_amount'] ?? 0,
            'total' => $total,
            'item_type' => $item['item_type'] ?? null,
            'item_id' => $item['item_id'] ?? null,
        ]);
    }

    protected function recalculateInvoiceTotals(Invoice $invoice): void
    {
        $items = $invoice->items;

        $invoice->subtotal = $items->sum(function ($item) {
            return $item->quantity * $item->unit_price;
        });

        $invoice->tax_amount = $items->sum('tax_amount');
        $invoice->discount_amount = $items->sum('discount_amount');
        $invoice->total_amount = $invoice->subtotal + $invoice->tax_amount - $invoice->discount_amount;
        $invoice->due_amount = $invoice->total_amount - $invoice->paid_amount;

        $invoice->save();
    }

    protected function updateInvoicePaymentStatus(Invoice $invoice): void
    {
        $invoice->paid_amount = $invoice->payments()->sum('amount');
        $invoice->due_amount = $invoice->total_amount - $invoice->paid_amount;

        if ($invoice->due_amount <= 0) {
            $invoice->status = InvoiceStatus::Paid;
            $invoice->paid_date = now()->toDateString();
        } elseif ($invoice->paid_amount > 0) {
            $invoice->status = InvoiceStatus::PartiallyPaid;
        }

        $invoice->save();
    }

    protected function generateInvoiceNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $lastInvoice = Invoice::where('invoice_number', 'like', "{$prefix}-{$date}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$date}-{$newNumber}";
    }

    public function getInvoiceStats(string $from, string $to): array
    {
        $invoices = Invoice::whereBetween('invoice_date', [$from, $to])->get();

        return [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('total_amount'),
            'total_paid' => $invoices->sum('paid_amount'),
            'total_due' => $invoices->sum('due_amount'),
            'by_status' => [
                'draft' => $invoices->where('status', InvoiceStatus::Draft)->count(),
                'sent' => $invoices->where('status', InvoiceStatus::Sent)->count(),
                'paid' => $invoices->where('status', InvoiceStatus::Paid)->count(),
                'partially_paid' => $invoices->where('status', InvoiceStatus::PartiallyPaid)->count(),
                'overdue' => $invoices->where('status', InvoiceStatus::Overdue)->count(),
            ],
            'overdue_amount' => $invoices->where('status', InvoiceStatus::Overdue)->sum('due_amount'),
        ];
    }
}
