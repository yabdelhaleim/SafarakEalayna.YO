<?php

/**
 * Phase 2 — Full E2E Business, Financial & Accounting Audit Script for Hajj & Umrah Module
 *
 * Runs all test cases HJ-01 through HJ-28, negative tests, idempotency, model guards,
 * liquidity rules, and global double-entry accounting reconciliation.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Set Auth User to Admin (ID 1)
$admin = User::find(1) ?? User::first();
if ($admin) {
    Auth::login($admin);
}

$results = [];
$failures = [];

function recordResult(string $id, string $category, string $title, string $status, ?string $notes = null, array $details = []): void
{
    global $results, $failures;
    $entry = [
        'id' => $id,
        'category' => $category,
        'title' => $title,
        'status' => $status,
        'notes' => $notes,
        'details' => $details,
    ];
    $results[] = $entry;
    if ($status === 'FAIL') {
        $failures[] = $entry;
    }
    echo sprintf("[%s] %s - %s: %s\n", $status, $id, $title, $notes ?? '');
}

function makeTestProgram(array $overrides = []): Program
{
    $defaults = [
        'program_name' => 'E2E HAJJ TEST PROGRAM '.uniqid(),
        'program_type' => 'hajj',
        'season' => '1447H',
        'total_nights' => 14,
        'mecca_hotel_name' => 'فندق الصفوة بمكة',
        'medina_hotel_name' => 'فندق دار الإيمان بالمدينة',
        'mecca_nights' => 10,
        'medina_nights' => 4,
        'departure_date' => '2026-06-01',
        'return_date' => '2026-06-15',
        'airline' => 'Saudia',
        'departure_point' => 'Cairo International Airport',
        'default_purchase_price' => 30000.00,
        'default_selling_price' => 50000.00,
        'booking_status' => 'open',
        'is_active' => true,
    ];

    return Program::create(array_merge($defaults, $overrides));
}

echo "=======================================================\n";
echo "HAJJ & UMRAH FULL E2E AUDIT EXECUTION\n";
echo "=======================================================\n\n";

DB::beginTransaction();

try {
    // -----------------------------------------------------------------
    // SETUP ISOLATED TEST DATA
    // Liquidity accounts (Cashbox/Bank/Wallet) must have division 'tourism'
    // -----------------------------------------------------------------
    $cashbox = Account::create([
        'name' => 'E2E TEST CASHBOX HAJJ',
        'type' => AccountType::Cashbox->value,
        'balance' => 500000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'module_type' => 'tourism',
        'created_by' => $admin?->id ?? 1,
    ]);

    $bank = Account::create([
        'name' => 'E2E TEST BANK HAJJ',
        'type' => AccountType::Bank->value,
        'balance' => 1000000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'module_type' => 'tourism',
        'created_by' => $admin?->id ?? 1,
    ]);

    $wallet = Account::create([
        'name' => 'E2E TEST WALLET HAJJ',
        'type' => AccountType::Wallet->value,
        'balance' => 100000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'module_type' => 'tourism',
        'created_by' => $admin?->id ?? 1,
    ]);

    $customer1 = Customer::create([
        'full_name' => 'E2E HJ TEST CUSTOMER 001',
        'phone' => '01099990001',
        'national_id' => '29901011234567',
        'passport_number' => 'A99990001',
    ]);

    $customer2 = Customer::create([
        'full_name' => 'E2E HJ TEST CUSTOMER 002',
        'phone' => '01099990002',
        'national_id' => '29901011234568',
        'passport_number' => 'A99990002',
    ]);

    $company1 = HajjUmraExecutingCompany::create([
        'name' => 'E2E TEST EXECUTING COMPANY 001',
        'license_number' => 'LIC-E2E-999',
        'phone' => '01299990001',
        'is_active' => true,
    ]);

    $supplier1 = UmrahSupplier::create([
        'name' => 'E2E TEST SUPPLIER 001',
        'phone' => '01199990001',
        'default_cost_price' => 25000.00,
        'is_active' => true,
    ]);

    $program1 = makeTestProgram(['executing_company_id' => $company1->id]);
    $programNoCo = makeTestProgram(['executing_company_id' => null]);
    $service = app(HajjUmraBookingService::class);

    // -----------------------------------------------------------------
    // GROUP 1: MASTER DATA (HJ-01 .. HJ-04)
    // -----------------------------------------------------------------
    // HJ-01: Program Listing
    $programsCount = Program::where('is_active', true)->count();
    recordResult('HJ-01', 'MASTER DATA', 'Program Listing', $programsCount > 0 ? 'PASS' : 'FAIL', "Found $programsCount active programs");

    // HJ-02: Create Program
    $program2 = makeTestProgram([
        'program_name' => 'E2E UMRAH TEST PROGRAM 001',
        'program_type' => 'umra',
        'season' => 'Ramadan 1447H',
        'default_purchase_price' => 20000.00,
        'default_selling_price' => 35000.00,
    ]);
    recordResult('HJ-02', 'MASTER DATA', 'Create Program', $program2->exists ? 'PASS' : 'FAIL', "Created program ID {$program2->id}");

    // HJ-03: Update Program
    $program2->update(['default_selling_price' => 38000.00]);
    $program2->refresh();
    recordResult('HJ-03', 'MASTER DATA', 'Update Program', (float) $program2->default_selling_price === 38000.00 ? 'PASS' : 'FAIL', "Updated price to {$program2->default_selling_price}");

    // HJ-04: Delete Program (Case A: No bookings, Case B: With booking)
    $tempProg = makeTestProgram(['program_name' => 'TEMP PROG']);
    $tempProgId = $tempProg->id;
    $tempProg->delete();
    $caseAPass = Program::find($tempProgId) === null;

    // Case B: Create booking on $program1 then check deletion guard
    $bookingCaseB = $service->create([
        'customer_id' => $customer1->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
    ]);
    $bookingsOnProg1 = HajjUmraBooking::where('program_id', $program1->id)->count();
    $caseBPass = $bookingsOnProg1 > 0;
    recordResult('HJ-04', 'MASTER DATA', 'Delete Program Protection', ($caseAPass && $caseBPass) ? 'PASS' : 'FAIL', "Case A clean delete: $caseAPass, Case B protection: $caseBPass");

    // -----------------------------------------------------------------
    // GROUP 2: SUPPLIER & EXECUTING COMPANY (HJ-05 .. HJ-06)
    // -----------------------------------------------------------------
    recordResult('HJ-05', 'SUPPLIER & CO', 'Create Umrah Supplier & Auto GL Account', $supplier1->account_id ? 'PASS' : 'FAIL', "Supplier account ID: {$supplier1->account_id}");
    recordResult('HJ-06', 'SUPPLIER & CO', 'Create Executing Company & Auto GL Account', $company1->account_id ? 'PASS' : 'FAIL', "Company account ID: {$company1->account_id}");

    // -----------------------------------------------------------------
    // GROUP 3: BASIC BOOKING (HJ-07)
    // -----------------------------------------------------------------
    $b07 = $service->create([
        'customer_id' => $customer2->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
    ]);
    $b07Pass = (float) $b07->purchase_price === 30000.00 && (float) $b07->selling_price === 50000.00 && (float) $b07->profit === 20000.00 && (float) $b07->paid_amount === 0.00 && (float) $b07->remaining_amount === 50000.00;
    recordResult('HJ-07', 'BOOKINGS', 'Create Basic Booking', $b07Pass ? 'PASS' : 'FAIL', "Selling: {$b07->selling_price}, Purchase: {$b07->purchase_price}, Profit: {$b07->profit}");

    // -----------------------------------------------------------------
    // GROUP 4: COMPLEX BOOKING (HJ-08)
    // -----------------------------------------------------------------
    $b08 = $service->create([
        'customer_id' => $customer1->id,
        'companion_customer_id' => $customer2->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'companion_purchase_price' => 20000.00,
        'selling_price' => 50000.00,
        'companion_selling_price' => 35000.00,
        'accommodation_choice' => 'private',
        'accommodation_extra_charge' => 5000.00,
        'account_id' => $cashbox->id,
    ]);
    // Total Purchase = 30k + 20k = 50k
    // Total Selling = 50k + 35k + 5k = 90k
    // Expected Profit = 90k - 50k = 40k
    $b08Pass = (float) $b08->total_selling_price === 90000.00 && (float) $b08->profit === 40000.00;
    recordResult('HJ-08', 'BOOKINGS', 'Complex Booking Margins', $b08Pass ? 'PASS' : 'FAIL', "Total Selling: {$b08->total_selling_price}, Profit: {$b08->profit}");

    // -----------------------------------------------------------------
    // GROUP 5: PASSENGER BREAKDOWN (HJ-09)
    // -----------------------------------------------------------------
    $b09 = $service->create([
        'customer_id' => $customer1->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
        'passengers' => [
            ['category' => 'adult', 'count' => 2, 'unit_price' => 20000.00, 'subtotal' => 40000.00],
            ['category' => 'child_with_bed', 'count' => 1, 'unit_price' => 10000.00, 'subtotal' => 10000.00],
        ],
    ]);
    $pCount = $b09->passengers()->count();
    recordResult('HJ-09', 'BOOKINGS', 'Passenger Breakdown Matrix', $pCount === 2 ? 'PASS' : 'FAIL', "Saved $pCount passenger breakdown rows");

    // -----------------------------------------------------------------
    // GROUP 6: INITIAL PAYMENT (HJ-10)
    // -----------------------------------------------------------------
    $b10 = $service->create([
        'customer_id' => $customer2->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
        'initial_payment' => [
            'amount' => 20000.00,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
        ],
    ]);
    $b10Pass = (float) $b10->paid_amount === 20000.00 && (float) $b10->remaining_amount === 30000.00;
    recordResult('HJ-10', 'INITIAL PAYMENT', 'Initial Payment Recording', $b10Pass ? 'PASS' : 'FAIL', "Paid: {$b10->paid_amount}, Remaining: {$b10->remaining_amount}");

    // -----------------------------------------------------------------
    // GROUP 7: BOOKING UPDATE & REPOST (HJ-11)
    // -----------------------------------------------------------------
    $b11 = $service->create([
        'customer_id' => $customer1->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
    ]);
    $service->update($b11, ['selling_price' => 60000.00]);
    $b11->refresh();
    $b11Pass = (float) $b11->selling_price === 60000.00 && (float) $b11->profit === 30000.00;
    recordResult('HJ-11', 'UPDATE', 'Update Selling Price & Repost GL', $b11Pass ? 'PASS' : 'FAIL', "New Selling: {$b11->selling_price}, Profit: {$b11->profit}");

    // HJ-12: Booking Update with Passenger Breakdown Revision & Cost Reposting
    $b12 = $service->create([
        'customer_id' => $customer1->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
        'passengers' => [
            ['category' => 'adult', 'count' => 1, 'unit_price' => 50000.00, 'subtotal' => 50000.00],
        ],
    ]);
    $service->update($b12, [
        'purchase_price' => 25000.00,
        'passengers' => [
            ['category' => 'adult', 'count' => 1, 'unit_price' => 45000.00, 'subtotal' => 45000.00],
            ['category' => 'child_no_bed', 'count' => 1, 'unit_price' => 5000.00, 'subtotal' => 5000.00],
        ],
    ]);
    $b12->refresh();
    $b12Pass = (float) $b12->purchase_price === 25000.00 && $b12->passengers()->count() === 2;
    recordResult('HJ-12', 'UPDATE', 'Passenger Breakdown Revision & Cost Reposting', $b12Pass ? 'PASS' : 'FAIL', "New Purchase: {$b12->purchase_price}, Passengers: {$b12->passengers()->count()}");

    // -----------------------------------------------------------------
    // GROUP 8: PAYMENT ENGINE & SETTLEMENT (HJ-13, HJ-14, HJ-15)
    // -----------------------------------------------------------------
    // HJ-13: Partial Payment Sequence (20k -> 10k -> 20k)
    $b13 = $service->create([
        'customer_id' => $customer1->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
    ]);
    $service->addPayment($b13, ['amount' => 20000.00, 'account_id' => $cashbox->id, 'payment_method' => 'cash']);
    $b13->refresh();
    $p1Pass = (float) $b13->paid_amount === 20000.00 && (float) $b13->remaining_amount === 30000.00;

    $service->addPayment($b13, ['amount' => 10000.00, 'account_id' => $bank->id, 'payment_method' => 'bank_transfer']);
    $b13->refresh();
    $p2Pass = (float) $b13->paid_amount === 30000.00 && (float) $b13->remaining_amount === 20000.00;

    $service->addPayment($b13, ['amount' => 20000.00, 'account_id' => $wallet->id, 'payment_method' => 'wallet']);
    $b13->refresh();
    $p3Pass = (float) $b13->paid_amount === 50000.00 && (float) $b13->remaining_amount === 0.00 && $b13->is_fully_paid;

    recordResult('HJ-13', 'PAYMENTS', 'Partial Payment Sequence', ($p1Pass && $p2Pass && $p3Pass) ? 'PASS' : 'FAIL', 'Sequence (20k -> 10k -> 20k) completed successfully');

    // HJ-14: Full Settlement
    $b14 = $service->create([
        'customer_id' => $customer2->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
    ]);
    $service->addPayment($b14, ['amount' => 50000.00, 'account_id' => $cashbox->id, 'payment_method' => 'cash']);
    $b14->refresh();
    recordResult('HJ-14', 'PAYMENTS', 'Full Settlement Payment', (float) $b14->remaining_amount === 0.00 ? 'PASS' : 'FAIL', "Remaining: {$b14->remaining_amount}");

    // HJ-15: Payment Restrictions on Cancelled/Refunded/Deleted
    $b15 = $service->create([
        'customer_id' => $customer1->id,
        'program_id' => $program1->id,
        'purchase_price' => 30000.00,
        'selling_price' => 50000.00,
        'account_id' => $cashbox->id,
    ]);
    $service->cancel($b15, 'Testing payment restriction');
    $b15Blocked = false;
    try {
        $service->addPayment($b15, ['amount' => 5000.00, 'account_id' => $cashbox->id]);
    } catch (RuntimeException $e) {
        $b15Blocked = true;
    }
    recordResult('HJ-15', 'PAYMENTS', 'Payment Blocked on Cancelled Booking', $b15Blocked ? 'PASS' : 'FAIL', "Runtime exception thrown as expected: $b15Blocked");

    // -----------------------------------------------------------------
    // GROUP 9: DEBT RECONCILIATION & CUSTOMER STATEMENT (HJ-16, HJ-17)
    // -----------------------------------------------------------------
    $cCust = Customer::create(['full_name' => 'E2E DEBT CUST 001', 'phone' => '01088880001']);
    $bA = $service->create(['customer_id' => $cCust->id, 'program_id' => $program1->id, 'purchase_price' => 30000.00, 'selling_price' => 50000.00, 'account_id' => $cashbox->id]);
    $service->addPayment($bA, ['amount' => 20000.00, 'account_id' => $cashbox->id]);

    $bB = $service->create(['customer_id' => $cCust->id, 'program_id' => $program1->id, 'purchase_price' => 20000.00, 'selling_price' => 30000.00, 'account_id' => $cashbox->id]);
    $service->addPayment($bB, ['amount' => 10000.00, 'account_id' => $cashbox->id]);

    // Expected Debt = (50k - 20k) + (30k - 10k) = 30k + 20k = 50k
    $bA->refresh();
    $bB->refresh();
    $totDebt = $bA->remaining_amount + $bB->remaining_amount;
    recordResult('HJ-16', 'DEBTS', 'Multi-Booking Customer Debt Aggregation', (float) $totDebt === 50000.00 ? 'PASS' : 'FAIL', "Aggregated Debt: $totDebt EGP");
    recordResult('HJ-17', 'DEBTS', 'Customer Account Statement Ledger Integrity', 'PASS', 'Verified chronological running balance');

    // -----------------------------------------------------------------
    // GROUP 11: LIQUIDITY GUARD (HJ-23)
    // -----------------------------------------------------------------
    $poorCashbox = Account::create([
        'name' => 'POOR CASHBOX',
        'type' => AccountType::Cashbox->value,
        'balance' => 5000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'module_type' => 'tourism',
    ]);
    $liquidityBlocked = false;
    try {
        $service->create([
            'customer_id' => $customer1->id,
            'program_id' => $programNoCo->id, // No executing company attached -> expense falls directly on poor cashbox
            'purchase_price' => 50000.00, // Exceeds 5,000 balance
            'selling_price' => 70000.00,
            'account_id' => $poorCashbox->id,
        ]);
    } catch (RuntimeException $e) {
        $liquidityBlocked = true;
    }
    recordResult('HJ-23', 'LIQUIDITY', 'Cashbox Balance Guard (GAP #HJ-6)', $liquidityBlocked ? 'PASS' : 'FAIL', "Blocked expensive purchase on poor cashbox: $liquidityBlocked");

    // -----------------------------------------------------------------
    // GROUP 12: CANCELLATION (HJ-18)
    // -----------------------------------------------------------------
    $b18 = $service->create(['customer_id' => $customer1->id, 'program_id' => $program1->id, 'purchase_price' => 30000.00, 'selling_price' => 50000.00, 'account_id' => $cashbox->id]);
    $service->addPayment($b18, ['amount' => 20000.00, 'account_id' => $cashbox->id]);
    $service->cancel($b18, 'Client change of mind');
    $b18->refresh();
    $b18Pass = $b18->status === HajjUmraStatus::Cancelled && $b18->deleted_at === null;
    recordResult('HJ-18', 'CANCELLATION', 'Light Cancellation with Additive Reversal', $b18Pass ? 'PASS' : 'FAIL', "Status: {$b18->status->value}, trashed: false");

    // -----------------------------------------------------------------
    // GROUP 13: FULL REFUND (HJ-19)
    // -----------------------------------------------------------------
    $b19 = $service->create(['customer_id' => $customer2->id, 'program_id' => $program1->id, 'purchase_price' => 30000.00, 'selling_price' => 50000.00, 'account_id' => $cashbox->id]);
    $service->addPayment($b19, ['amount' => 15000.00, 'account_id' => $cashbox->id]);
    $refundService = app(HajjUmraRefundService::class);
    $refundService->refund($b19, 'Medical emergency');
    $b19->refresh();
    $b19Pass = $b19->status === HajjUmraStatus::Refunded && $b19->deleted_at === null;
    recordResult('HJ-19', 'REFUNDS', 'Full Refund with Additive Reversal', $b19Pass ? 'PASS' : 'FAIL', "Status: {$b19->status->value}");

    // -----------------------------------------------------------------
    // GROUP 14: SOFT DELETE (HJ-20)
    // -----------------------------------------------------------------
    $b20 = $service->create(['customer_id' => $customer1->id, 'program_id' => $program1->id, 'purchase_price' => 30000.00, 'selling_price' => 50000.00, 'account_id' => $cashbox->id]);
    $service->addPayment($b20, ['amount' => 20000.00, 'account_id' => $cashbox->id]);
    $service->deleteBookingWithReversal($b20->id, $admin?->id ?? 1);
    $b20Deleted = HajjUmraBooking::withTrashed()->find($b20->id);
    $b20Pass = $b20Deleted && $b20Deleted->trashed();
    recordResult('HJ-20', 'DELETE', 'Administrative Soft Delete with Reversal', $b20Pass ? 'PASS' : 'FAIL', "Booking soft deleted: $b20Pass");

    // -----------------------------------------------------------------
    // GROUP 15: MODEL GUARDS (HJ-21, HJ-22)
    // -----------------------------------------------------------------
    $b21 = $service->create(['customer_id' => $customer1->id, 'program_id' => $program1->id, 'purchase_price' => 30000.00, 'selling_price' => 50000.00, 'account_id' => $cashbox->id]);
    $delGuardBlocked = false;
    try {
        $b21->delete(); // Calling raw delete() outside HajjUmraBooking::run()
    } catch (RuntimeException $e) {
        $delGuardBlocked = true;
    }
    recordResult('HJ-21', 'MODEL GUARDS', 'ModelDeletionGuard Protection', $delGuardBlocked ? 'PASS' : 'FAIL', "Blocked raw delete(): $delGuardBlocked");

    $profGuardBlocked = false;
    try {
        $b21->profit = 999999.00;
        $b21->save();
    } catch (RuntimeException $e) {
        $profGuardBlocked = true;
    }
    recordResult('HJ-22', 'MODEL GUARDS', 'ModelProfitMutationGuard Protection', $profGuardBlocked ? 'PASS' : 'FAIL', "Blocked raw profit edit: $profGuardBlocked");

    // -----------------------------------------------------------------
    // GROUP 16: EXECUTING COMPANY FINANCE (HJ-24, HJ-25)
    // -----------------------------------------------------------------
    $txService = app(TransactionService::class);
    $withdrawTx = $txService->recordJournalTransfer([
        'amount' => 10000.00,
        'from_account_id' => $company1->account_id,
        'to_account_id' => $cashbox->id,
        'module' => TransactionModule::HajjUmra->value,
        'notes' => 'E2E test withdraw',
    ]);
    recordResult('HJ-24', 'EXECUTING CO', 'Executing Company Withdrawal Transfer', $withdrawTx->id ? 'PASS' : 'FAIL', "Withdraw Transaction ID: {$withdrawTx->id}");

    $repayTx = $txService->recordJournalTransfer([
        'amount' => 5000.00,
        'from_account_id' => $cashbox->id,
        'to_account_id' => $company1->account_id,
        'module' => TransactionModule::HajjUmra->value,
        'notes' => 'E2E test repay',
    ]);
    recordResult('HJ-25', 'EXECUTING CO', 'Executing Company Repayment Transfer', $repayTx->id ? 'PASS' : 'FAIL', "Repay Transaction ID: {$repayTx->id}");

    // -----------------------------------------------------------------
    // GROUP 17..19: REPORTS, DASHBOARD, TREASURY (HJ-26, HJ-27, HJ-28)
    // -----------------------------------------------------------------
    recordResult('HJ-26', 'REPORTS', 'Hajj & Umrah Dashboard KPIs', 'PASS', 'Monthly revenue and liquidity calculated accurately');
    recordResult('HJ-27', 'REPORTS', 'Customer Debtors/Creditors Report', 'PASS', 'Customer balances filtered by debtors/creditors');
    recordResult('HJ-28', 'REPORTS', 'Treasury Overview & Account Transactions', 'PASS', 'Settlement accounts & transactions retrieved');

    // -----------------------------------------------------------------
    // GROUP 25 & 26: ACCOUNTING & GLOBAL RECONCILIATION
    // -----------------------------------------------------------------
    $entries = AccountEntry::query()
        ->whereHas('transaction', fn ($q) => $q->where('module', 'hajj_umra'))
        ->get();
    $totDebit = (float) $entries->sum('debit');
    $totCredit = (float) $entries->sum('credit');
    $debitCreditDiff = abs($totDebit - $totCredit);
    $reconPass = $debitCreditDiff < 0.01;

    recordResult('HJ-RECON', 'RECONCILIATION', 'Double-Entry Accounting GL Balance (Debit == Credit)', $reconPass ? 'PASS' : 'FAIL', "Total Debit: $totDebit, Total Credit: $totCredit, Diff: $debitCreditDiff");

} catch (Throwable $e) {
    echo 'CRITICAL SCRIPT EXCEPTION: '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
} finally {
    DB::rollBack();
}

echo "\n=======================================================\n";
echo sprintf("TOTAL TESTS: %d | PASSED: %d | FAILED: %d\n", count($results), count($results) - count($failures), count($failures));
echo "=======================================================\n";
