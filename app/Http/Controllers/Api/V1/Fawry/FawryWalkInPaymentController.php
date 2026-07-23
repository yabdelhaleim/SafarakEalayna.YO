<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Fawry\FawryTransaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Handles debt repayment for walk-in Fawry clients — i.e. Fawry transactions
 * where `client_id IS NULL` (no Customer record was linked at creation time).
 *
 * Per-client debt is sourced from `fawry_transactions` columns
 * (`selling_price - amount`) grouped by `client_name`. The unified walk-in
 * AR account ("ذمم عملاء فوري غير مسجلين") holds the GL balance.
 *
 * Payment is allocated FIFO (oldest unpaid transaction first) by increasing
 * the `amount` column on each transaction until the payment is exhausted.
 */
class FawryWalkInPaymentController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function payDebt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $clientName = trim($validated['client_name']);
        $amount = (float) $validated['amount'];

        try {
            return DB::transaction(function () use ($validated, $clientName, $amount) {
                $clearing = app(LedgerClearingAccounts::class);
                $walkInArAccountId = $clearing->fawryWalkInArAccountId();

                // Lock the AR account row to prevent concurrent payments
                Account::lockForUpdate()->findOrFail($walkInArAccountId);

                // Lock the settlement (paying) account row
                $payingAccount = Account::lockForUpdate()->findOrFail($validated['account_id']);

                $fromCurrency = strtoupper((string) $payingAccount->currency);
                if ($fromCurrency !== 'EGP') {
                    throw new \InvalidArgumentException(
                        'حساب التحصيل يجب أن يكون بالجنيه المصري (EGP). الحساب المختار: '.$fromCurrency
                    );
                }

                // Compute current debt for this walk-in client_name
                $debt = (float) DB::table('fawry_transactions')
                    ->whereNull('client_id')
                    ->where('client_name', $clientName)
                    ->selectRaw('COALESCE(SUM(selling_price - amount), 0) as debt')
                    ->value('debt');

                if ($debt <= 0.005) {
                    throw new \Exception(
                        "لا توجد مديونية مستحقة على العميل «{$clientName}» (السداد مكتمل أو لا توجد معاملات آجلة)."
                    );
                }

                // 🛡️ Overpayment guard (with 0.5 piaster tolerance)
                if ($amount > $debt + 0.005) {
                    throw new \Exception(
                        'مبلغ الدفع ('.number_format($amount, 2).' ج.م) يتجاوز المديونية الفعلية ('
                        .number_format($debt, 2).' ج.م) للعميل «'.$clientName.'». '
                        .'استخدم المبلغ الصحيح لتجنب الدفع الزائد.'
                    );
                }

                // FIFO allocation: oldest unpaid transactions first
                $remaining = $amount;
                $allocatedTransactions = [];

                $transactions = DB::table('fawry_transactions')
                    ->whereNull('client_id')
                    ->where('client_name', $clientName)
                    ->whereRaw('selling_price > amount')
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($transactions as $tx) {
                    if ($remaining <= 0.005) {
                        break;
                    }
                    $txDebt = (float) $tx->selling_price - (float) $tx->amount;
                    if ($txDebt <= 0) {
                        continue;
                    }
                    $allocate = min($remaining, $txDebt);

                    DB::table('fawry_transactions')
                        ->where('id', $tx->id)
                        ->update([
                            'amount' => DB::raw('amount + '.$allocate),
                            'updated_at' => now(),
                        ]);

                    $allocatedTransactions[] = [
                        'id' => $tx->id,
                        'allocated' => round($allocate, 2),
                        'selling_price' => (float) $tx->selling_price,
                        'new_amount' => round((float) $tx->amount + $allocate, 2),
                    ];
                    $remaining -= $allocate;
                }

                // Journal transfer: walk-in AR account → settlement (cashbox).
                // Direction: from AR (debit, reduces debt) → to settlement
                // (credit, increases cash). The cash flow is opposite to the
                // creation flow (creation credits AR from income clearing;
                // payment debits AR to settlement).
                $tx = $this->transactionService->recordJournalTransfer([
                    'amount' => $amount,
                    'from_account_id' => $walkInArAccountId,
                    'to_account_id' => $validated['account_id'],
                    'module' => TransactionModule::Fawry->value,
                    'related_type' => FawryTransaction::class,
                    'related_id' => null,
                    'notes' => ($validated['notes'] ?? 'تسديد مديونية فوري')
                        .' — عميل غير مسجل: '.$clientName,
                    'created_by' => Auth::id() ?? 1,
                    'allow_from_negative' => false,
                ]);

                $remainingDebt = (float) round((float) $debt - (float) $amount, 2);
                $fullySettled = $remainingDebt <= 0.005;

                return ApiResponse::success(
                    $fullySettled
                        ? 'تم تسديد مديونية فوري بالكامل بنجاح.'
                        : 'تم تسديد جزء من مديونية فوري بنجاح.',
                    [
                        'transaction_id' => $tx->id,
                        'client_name' => $clientName,
                        'amount' => round($amount, 2),
                        'previous_debt' => round($debt, 2),
                        'remaining_debt' => $remainingDebt,
                        'fully_settled' => $fullySettled,
                        'allocated_to' => $allocatedTransactions,
                        'ar_account_id' => $walkInArAccountId,
                        'ar_account_balance' => (float) Account::find($walkInArAccountId)->fresh()->balance,
                    ]
                );
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
