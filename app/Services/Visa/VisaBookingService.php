<?php

namespace App\Services\Visa;

use App\Enums\TransactionModule;
use App\Enums\VisaStatus;
use App\Models\Customer;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Models\VisaPayment;
use App\Services\Finance\TransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VisaBookingService
{
    public function __construct(protected TransactionService $transactions) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = VisaBooking::with([
            'customer',
            'visaDetail.agent',
            'visaDetail.durationRow',
            'employee',
            'account',
            'payments.account',
        ]);

        $this->applyFilters($query, $filters);

        $perPage = (int) min($filters['per_page'] ?? 15, 100);

        return $query->latest()->paginate($perPage);
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['country'])) {
            $query->whereHas('visaDetail', fn ($q) => $q->where('country', $filters['country']));
        }
        if (!empty($filters['visa_type'])) {
            $query->whereHas('visaDetail', fn ($q) => $q->where('visa_type', $filters['visa_type']));
        }
        if (!empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->whereHas('customer', function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('passport_number', 'like', "%{$term}%");
            });
        }
    }

    public function find(int $id): VisaBooking
    {
        return VisaBooking::with([
            'customer',
            'visaDetail.agent',
            'visaDetail.durationRow',
            'employee',
            'account',
            'expenseTransaction',
            'incomeTransaction',
            'payments.account',
            'payments.transaction',
        ])->findOrFail($id);
    }

    public function create(array $data): VisaBooking
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->resolveCustomer($data['customer'] ?? null, $data['customer_id'] ?? null);

            $detailData = $data['visa_details'] ?? [];
            $detail = VisaDetail::create([
                'visa_type' => $detailData['visa_type'] ?? null,
                'country' => $detailData['country'] ?? null,
                'duration' => $detailData['duration'] ?? null,
                'visa_duration_id' => $detailData['visa_duration_id'] ?? null,
                'entry_type' => $detailData['entry_type'] ?? null,
                'validity_from' => $detailData['validity_from'] ?? null,
                'validity_to' => $detailData['validity_to'] ?? null,
                'executing_company' => $detailData['executing_company'] ?? null,
                'executing_agent' => $detailData['executing_agent'] ?? null,
                'executing_agent_contact' => $detailData['executing_agent_contact'] ?? null,
                'visa_agent_id' => $detailData['visa_agent_id'] ?? null,
                'submission_date' => $detailData['submission_date'] ?? now(),
                'expected_result_date' => $detailData['expected_result_date'] ?? null,
                'visa_number' => $detailData['visa_number'] ?? null,
                'status' => $detailData['status'] ?? VisaStatus::Submitted->value,
            ]);

            $purchase = (float) $data['purchase_price'];
            $selling = (float) $data['selling_price'];
            $serviceFee = (float) ($data['service_fee'] ?? 0);
            $profit = round(($selling + $serviceFee) - $purchase, 2);

            $accountId = (int) ($data['account_id'] ?? 0);
            if ($accountId === 0) {
                $vault = \App\Models\Account::getModuleVault('visas');
                if (!$vault) {
                    throw new \RuntimeException('لم يتم العثور على الخزينة الرسمية لموديول التأشيرات. يرجى اختيار حساب أو ضبط الخزينة الرسمية.');
                }
                $accountId = $vault->id;
            }
            
            $createdBy = Auth::id() ?? ($data['employee_id'] ?? null);

            $booking = VisaBooking::create([
                'customer_id' => $customer->id,
                'visa_detail_id' => $detail->id,
                'module' => TransactionModule::Visa->value,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'service_fee' => $serviceFee,
                'profit' => $profit,
                'currency' => $data['currency'] ?? 'EGP',
                'status' => $data['status'] ?? VisaStatus::Submitted->value,
                'agent_name' => $data['agent_name'] ?? ($customer->full_name ?? ''),
                'notes' => $data['notes'] ?? null,
                'account_id' => $accountId,
                'employee_id' => $data['employee_id'] ?? $createdBy,
                'created_by' => $createdBy,
            ]);

            $expense = $this->transactions->recordExpense([
                'amount' => $purchase,
                'from_account_id' => $accountId,
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => "تكلفة تأشيرة {$detail->country} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $income = $this->transactions->recordIncome([
                'amount' => $selling + $serviceFee,
                'to_account_id' => $accountId,
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => "بيع تأشيرة {$detail->country} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $booking->update([
                'expense_transaction_id' => $expense->id,
                'income_transaction_id' => $income->id,
            ]);

            if (!empty($data['initial_payment']) && (float) ($data['initial_payment']['amount'] ?? 0) > 0) {
                $this->addPayment($booking, $data['initial_payment']);
            }

            Log::info('Visa booking created', [
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'detail_id' => $detail->id,
                'profit' => $profit,
            ]);

            return $this->find($booking->id);
        });
    }

    public function update(VisaBooking $booking, array $data): VisaBooking
    {
        return DB::transaction(function () use ($booking, $data) {
            $fields = collect($data)->only([
                'status', 'agent_name', 'notes', 'employee_id',
            ])->all();

            if (array_key_exists('purchase_price', $data) || array_key_exists('selling_price', $data) || array_key_exists('service_fee', $data)) {
                $purchase = (float) ($data['purchase_price'] ?? $booking->purchase_price);
                $selling = (float) ($data['selling_price'] ?? $booking->selling_price);
                $fee = (float) ($data['service_fee'] ?? $booking->service_fee ?? 0);
                $fields['purchase_price'] = $purchase;
                $fields['selling_price'] = $selling;
                $fields['service_fee'] = $fee;
                $fields['profit'] = round(($selling + $fee) - $purchase, 2);
            }

            $booking->update($fields);

            if (!empty($data['visa_details']) && is_array($data['visa_details']) && $booking->visaDetail) {
                $detailPayload = collect($data['visa_details'])
                    ->only([
                        'visa_type', 'country', 'duration', 'visa_duration_id', 'entry_type',
                        'validity_from', 'validity_to', 'executing_company', 'executing_agent',
                        'executing_agent_contact', 'visa_agent_id', 'submission_date',
                        'expected_result_date', 'visa_number',
                    ])
                    ->all();
                if ($detailPayload !== []) {
                    $booking->visaDetail->update($detailPayload);
                }
            }

            // رقم التأشيرة من الحقل المسطح (لتوافق الطلبات القديمة)
            if (array_key_exists('visa_number', $data) && $data['visa_number'] !== null && $booking->visaDetail) {
                $booking->visaDetail->update(['visa_number' => $data['visa_number']]);
            }

            return $this->find($booking->id);
        });
    }

    public function cancel(VisaBooking $booking, ?string $reason = null): VisaBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            $booking->update([
                'status' => VisaStatus::Cancelled->value,
                'notes' => $note,
            ]);

            $booking->visaDetail?->update(['status' => VisaStatus::Cancelled->value]);

            return $this->find($booking->id);
        });
    }

    public function addPayment(VisaBooking $booking, array $data): VisaPayment
    {
        return DB::transaction(function () use ($booking, $data) {
            $amount = (float) $data['amount'];
            $accountId = (int) ($data['account_id'] ?? $booking->account_id);
            $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

            $income = $this->transactions->recordIncome([
                'amount' => $amount,
                'to_account_id' => $accountId,
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => "دفعة على تأشيرة #{$booking->id}",
                'created_by' => $createdBy,
            ]);

            return $booking->payments()->create([
                'payment_method' => $data['payment_method'] ?? 'cash',
                'amount' => $amount,
                'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
                'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
                'account_id' => $accountId,
                'transaction_id' => $income->id,
                'transaction_reference' => $data['reference'] ?? $data['transaction_reference'] ?? null,
                'payment_date' => $data['payment_date'] ?? now(),
                'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
                'created_by' => $createdBy,
            ]);
        });
    }

    protected function resolveCustomer(?array $data, ?int $existingId): Customer
    {
        if ($existingId) {
            return Customer::findOrFail($existingId);
        }

        if (!$data || empty($data['phone'])) {
            throw new \InvalidArgumentException('بيانات العميل (الاسم والهاتف) مطلوبة.');
        }

        return Customer::updateOrCreate(
            ['phone' => $data['phone']],
            collect($data)->only([
                'full_name', 'national_id', 'passport_number', 'passport_expiry',
                'date_of_birth', 'city', 'affiliation', 'notes',
            ])->all()
        );
    }
}
