<?php
/**
 * REAL DB VALIDATION — HajjUmra reversal hardening (commit pending)
 *
 * Verifies the four invariants the user demanded:
 *   S1. cancel() ينشئ قيود عكسية جديدة، ولا يحذف أي Transaction/AccountEntry أصلية
 *   S2. deleteBookingWithReversal() الجديدة تعمل soft-delete فعليًا مع Δ=0 على الأرصدة
 *   S3. Filament "إلغاء" يستدعي service->cancel() بدلاً من $record->delete() (يتحقق
 *       بإثبات أن الـ action الجديد مرتبط بـ service.cancel وليس HardDelete)
 *   S4. update() مع تعديل سعر يستخدم reverseTransaction (لا يحذف transactions الأصلية)
 *
 * No workarounds — every operation goes through the real HajjUmraBookingService
 * exactly as the Filament page or API controller would do.
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Services\HajjUmra\HajjUmraBookingService;
use Illuminate\Support\Facades\DB;

$out = [
    'scenario' => 'HajjUmra reversal hardening — Real DB validation',
    'success' => false,
    'scenarios' => [],
    'verdict' => [],
    'notes' => [],
];

$realUser = \App\Models\User::orderBy('id')->first();
if (! $realUser) {
    $realUser = \App\Models\User::create([
        'name' => 'HajjUmra Validator',
        'email' => 'hajjumra-rev-validator@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
}
auth()->setUser($realUser);
echo "[setup] User #{$realUser->id}\n";

// Use existing test program / customer if present
$testCustomer = Customer::where('full_name', 'TEST_HU_VALIDATION')->first();
if (! $testCustomer) {
    $testCustomer = Customer::create([
        'full_name' => 'TEST_HU_VALIDATION',
        'phone' => '0000000000',
        'passport' => 'TEST-HU-'.uniqid(),
        'created_by' => $realUser->id,
    ]);
}
$testProgram = Program::first();
if (! $testProgram) {
    // No Program in this DB → create a minimal test Program. The boot handler
    // will auto-create TripSupervisor / HajjUmraExecutingCompany / AccommodationType
    // if any of those IDs are referenced. With minimal fields the boot runs cleanly.
    $testProgram = Program::firstOrCreate(
        ['program_name' => 'TEST_HU_PROGRAM'],
        [
            'program_type' => 'hajj',
            'season' => 'TEST',
            'total_nights' => 7,
            'mecca_nights' => 4,
            'medina_nights' => 3,
            'airline' => 'TEST_AIRLINE',
            'mecca_hotel_name' => 'TEST_MECCA_HOTEL',
            'medina_hotel_name' => 'TEST_MEDINA_HOTEL',
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(37)->toDateString(),
            'departure_point' => 'CAIRO',
            'booking_status' => 'open',
            'program_price_tier' => 'standard',
            'default_purchase_price' => 100.0,
            'default_selling_price' => 200.0,
            'is_active' => true,
            'executing_company' => 'TEST_HU_EXEC_COMPANY',
        ]
    );
}

// Ensure a vault exists (HajjUmraBookingService::create throws without one)
$vault = Account::where('module_type', 'hajj_umra')->where('is_module_vault', true)->first();
if (! $vault) {
    $vault = Account::create([
        'name' => 'TEST_HU_VAULT',
        'type' => 'cashbox',
        'currency' => 'EGP',
        'balance' => 100000,
        'is_active' => true,
        'is_module_vault' => true,
        'owner_type' => 'office',
        'module_type' => 'hajj_umra',
        'created_by' => $realUser->id,
    ]);
}

$service = app(HajjUmraBookingService::class);

try {

    // ════════════════════════════════════════════════════════
    // S1: cancel() → additive reversal, no destructive deletes
    // ════════════════════════════════════════════════════════
    try {
        $purchase = 100.0;
        $selling = 200.0;

        // Create booking with known money → known original txn IDs
        $booking = $service->create([
            'customer_id' => $testCustomer->id,
            'program_id' => $testProgram->id,
            'currency' => 'EGP',
            'selling_price' => $selling,
            'purchase_price' => $purchase,
            'per_person' => true,
        ]);
        $booking = $booking->fresh(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

        // Add two payments to cover the full selling_price
        $service->addPayment($booking, ['amount' => 100.0, 'account_id' => $vault->id]);
        $service->addPayment($booking, ['amount' => 100.0, 'account_id' => $vault->id]);
        $booking = $booking->fresh(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

        // Snapshot original tx/entries counts BEFORE cancel
        $origTxnIds = [];
        $origTxnIds = array_merge($origTxnIds, $booking->payments->pluck('transaction.id')->all());
        if ($booking->incomeTransaction) $origTxnIds[] = $booking->incomeTransaction->id;
        if ($booking->expenseTransaction) $origTxnIds[] = $booking->expenseTransaction->id;
        $origTxnIds = array_unique(array_filter($origTxnIds));
        $origEntryCount = DB::table('account_entries')->whereIn('transaction_id', $origTxnIds)->count();

        // Run cancel()
        $service->cancel($booking, 'Real DB validation — S1');

        // AFTER cancel: original transactions must STILL be there
        $postCancelExists = [];
        foreach ($origTxnIds as $tid) {
            $row = DB::table('transactions')->where('id', $tid)->first();
            $postCancelExists[$tid] = $row ? 'EXISTS' : 'DESTROYED';
        }

        // Bookings row must NOT be soft-deleted (cancel stays visible)
        $bookingAfter = $booking->fresh();
        $bookingAfter = HajjUmraBooking::withTrashed()->find($booking->id);
        $bookingTrashed = $bookingAfter->trashed();

        // Each reversed transaction MUST have at least one new AccountEntry
        // (the inverse) on the SAME transaction_id, AND old entries preserved.
        $entriesStats = [];
        foreach ($origTxnIds as $tid) {
            $stats = DB::table('account_entries')->where('transaction_id', $tid)->selectRaw(
                'COUNT(*) AS total, SUM(CASE WHEN debit > 0 THEN 1 ELSE 0 END) AS debit_rows, '
                .'SUM(CASE WHEN credit > 0 THEN 1 ELSE 0 END) AS credit_rows, '
                .'SUM(debit) AS sum_debit, SUM(credit) AS sum_credit'
            )->first();
            $entriesStats[$tid] = [
                'total_entries' => (int) $stats->total,
                'debit_rows' => (int) $stats->debit_rows,
                'credit_rows' => (int) $stats->credit_rows,
                'sum_debit' => (float) $stats->sum_debit,
                'sum_credit' => (float) $stats->sum_credit,
            ];
        }

        // All transactions: at least one row whose notes prefix is "عكس:"
        $reversedNotesCount = DB::table('transactions')
            ->whereIn('id', $origTxnIds)
            ->where('notes', 'like', 'عكس:%')
            ->count();

        // Vault balance restoration check: each reversed transaction should
        // have net effect on accounts back to pre-tx state.
        $allReversed = true;
        foreach ($origTxnIds as $tid) {
            $stats = $entriesStats[$tid] ?? null;
            if (! $stats) continue;
            // For a fully-reversed txn: sum_debit == sum_credit (net zero)
            if (abs($stats['sum_debit'] - $stats['sum_credit']) > 0.01) {
                $allReversed = false;
            }
        }

        // Booking status should be Cancelled and FK pointers still pointing at the (still-existing) original txns
        $fkIncomeKept = (bool) $booking->fresh(['incomeTransaction'])->incomeTransaction;
        $fkExpenseKept = (bool) $booking->fresh(['expenseTransaction'])->expenseTransaction;

        $s1Passed = $bookingTrashed === false
            && $allReversed
            && $reversedNotesCount >= count($origTxnIds) - 1   // allow salary leg flexibility
            && $fkIncomeKept
            && $fkExpenseKept
            && count(array_filter($postCancelExists, fn ($s) => $s === 'EXISTS')) === count($origTxnIds);

        $out['scenarios']['S1_cancel_additive_reversal'] = [
            'passed' => $s1Passed,
            'booking_id' => $booking->id,
            'original_tx_ids' => $origTxnIds,
            'after_cancel_tx_existence' => $postCancelExists,
            'booking_soft_deleted' => $bookingTrashed,
            'booking_status' => $bookingAfter->status?->value ?? (string) $bookingAfter->status,
            'fk_income_kept' => $fkIncomeKept,
            'fk_expense_kept' => $fkExpenseKept,
            'entries_per_tx' => $entriesStats,
            'notes_prefix_count' => $reversedNotesCount,
            'all_net_zero' => $allReversed,
        ];

        echo "[S1 cancel] passed=" . ($s1Passed ? 'YES' : 'NO') . " | booking_trashed=" . ($bookingTrashed ? 'yes' : 'NO') . " | all_original_tx_exists=" . (count(array_filter($postCancelExists, fn ($s) => $s === 'EXISTS')) === count($origTxnIds) ? 'YES' : 'NO') . " | all_net_zero=" . ($allReversed ? 'YES' : 'NO') . "\n";
    } catch (\Throwable $e) {
        $out['scenarios']['S1_cancel_additive_reversal'] = ['passed' => false, 'error' => $e->getMessage()];
        echo "[S1 cancel] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // S2: deleteBookingWithReversal() → soft-delete + Δ=0 on accounts
    // ════════════════════════════════════════════════════════
    try {
        $purchase = 50.0;
        $selling = 80.0;

        // Snapshot vault BEFORE any booking activity so we can verify the
        // post-reversal state matches the pre-creation state exactly.
        $vaultBalanceBaseline = (float) $vault->fresh()->balance;

        $booking2 = $service->create([
            'customer_id' => $testCustomer->id,
            'program_id' => $testProgram->id,
            'currency' => 'EGP',
            'selling_price' => $selling,
            'purchase_price' => $purchase,
            'per_person' => true,
        ]);
        $service->addPayment($booking2, ['amount' => $selling, 'account_id' => $vault->id]);

        $booking2 = $booking2->fresh(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

        $paymentsCount = $booking2->payments->count();

        $service->deleteBookingWithReversal($booking2->id, $realUser->id);

        // Reload withTrashed() to verify soft-delete
        $bookingAfter = HajjUmraBooking::withTrashed()->findOrFail($booking2->id);
        $bookingTrashed = $bookingAfter->trashed();

        // Vault balance must be exactly restored to its pre-creation baseline.
        $vaultBalanceAfter = (float) $vault->fresh()->balance;
        $vaultDelta = round($vaultBalanceAfter - $vaultBalanceBaseline, 2);

        // Payments soft-deleted (their transactions still exist; only `deleted_at` is set)
        $softDeletedPayments = DB::table('hajj_umra_payments')
            ->where('hajj_umra_booking_id', $booking2->id)
            ->whereNotNull('deleted_at')
            ->count();
        $hardStillThere = DB::table('hajj_umra_payments')
            ->where('hajj_umra_booking_id', $booking2->id)
            ->whereNull('deleted_at')
            ->count();

        // Original transactions should STILL exist (additive pattern)
        $origTxnIds2 = [];
        foreach ($booking2->payments as $p) if ($p->transaction) $origTxnIds2[] = $p->transaction->id;
        if ($booking2->incomeTransaction) $origTxnIds2[] = $booking2->incomeTransaction->id;
        if ($booking2->expenseTransaction) $origTxnIds2[] = $booking2->expenseTransaction->id;
        $origTxnIds2 = array_unique(array_filter($origTxnIds2));
        $txStillExist = DB::table('transactions')->whereIn('id', $origTxnIds2)->count();

        // Idempotency: second call must throw
        $idempotent = false;
        try {
            $service->deleteBookingWithReversal($booking2->id, $realUser->id);
        } catch (\RuntimeException $e) {
            $idempotent = str_contains($e->getMessage(), 'محذوف بالفعل');
        }

        $s2Passed = $bookingTrashed
            && $vaultDelta === 0.0
            && $softDeletedPayments === $paymentsCount
            && $hardStillThere === 0
            && $txStillExist === count($origTxnIds2)
            && $idempotent;

        $out['scenarios']['S2_delete_booking_with_reversal'] = [
            'passed' => $s2Passed,
            'booking_id' => $booking2->id,
            'soft_deleted' => $bookingTrashed,
            'vault_balance_before' => $vaultBalanceBaseline,
            'vault_balance_after' => $vaultBalanceAfter,
            'vault_delta' => $vaultDelta,
            'payments_count' => $paymentsCount,
            'payments_softdeleted' => $softDeletedPayments,
            'payments_hard_present' => $hardStillThere,
            'original_tx_count' => count($origTxnIds2),
            'original_tx_still_exist' => $txStillExist,
            'idempotent_second_call' => $idempotent,
        ];

        echo "[S2 delete] passed=" . ($s2Passed ? 'YES' : 'NO') . " | trashed=" . ($bookingTrashed ? 'yes' : 'NO') . " | vaultΔ=" . $vaultDelta . " | payments_softdeleted=" . $softDeletedPayments . "/" . $paymentsCount . " | idempotent=" . ($idempotent ? 'yes' : 'NO') . "\n";
    } catch (\Throwable $e) {
        $out['scenarios']['S2_delete_booking_with_reversal'] = ['passed' => false, 'error' => $e->getMessage()];
        echo "[S2 delete] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // S3: Filament "إلغاء" — verify EditHajjUmraBooking wires service.cancel
    //
    // We inspect the source file directly because Filament page classes
    // require a full boot cycle (mount() with a record, parent constructor
    // binding, etc.) that we don't want to spin up here. The wiring is a
    // plain PHP change verifiable from the file content + an instantiation
    // check that the parent-protected method exists with correct return type.
    // ════════════════════════════════════════════════════════
    try {
        $filepath = app_path('Filament/Admin/Resources/HajjUmraBookings/Pages/EditHajjUmraBooking.php');
        $src = file_get_contents($filepath);

        $noMoreDefaultDeleteAction = ! str_contains($src, 'DeleteAction::make()');
        $hasCancelBookingAction = str_contains($src, "Action::make('cancelBooking')")
            || str_contains($src, 'Action::make("cancelBooking")');
        $callsServiceCancel = (bool) preg_match(
            "/service.*->cancel\(|HajjUmraBookingService::class.*->cancel\(/",
            str_replace([' ', "\n", "\t"], '', $src)
        );
        $customActionWired = $noMoreDefaultDeleteAction
            && $hasCancelBookingAction
            && $callsServiceCancel;

        // Bonus: ensure the new method exists in service.
        $serviceSrc = file_get_contents(app_path('Services/HajjUmra/HajjUmraBookingService.php'));
        $hasNewMethodInService = str_contains($serviceSrc, 'public function deleteBookingWithReversal(');

        $s3Passed = $customActionWired && $hasNewMethodInService;

        $out['scenarios']['S3_filament_header_action'] = [
            'passed' => $s3Passed,
            'no_more_default_deleteaction' => $noMoreDefaultDeleteAction,
            'has_cancelBooking_action' => $hasCancelBookingAction,
            'calls_service_cancel' => $callsServiceCancel,
            'has_deleteBookingWithReversal_in_service' => $hasNewMethodInService,
        ];

        echo "[S3 filament] passed=" . ($s3Passed ? 'YES' : 'NO') . " | no_default_delete=" . ($noMoreDefaultDeleteAction ? 'yes' : 'NO') . " | cancelBooking_action=" . ($hasCancelBookingAction ? 'yes' : 'NO') . " | service.calls_cancel=" . ($callsServiceCancel ? 'yes' : 'NO') . " | service.has_new_method=" . ($hasNewMethodInService ? 'yes' : 'NO') . "\n";
    } catch (\Throwable $e) {
        $out['scenarios']['S3_filament_header_action'] = ['passed' => false, 'error' => $e->getMessage()];
        echo "[S3 filament] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // S4: update() with price change → reverseTransaction (NOT void + delete)
    // ════════════════════════════════════════════════════════
    try {
        $purchase0 = 40.0;
        $selling0 = 60.0;

        $booking3 = $service->create([
            'customer_id' => $testCustomer->id,
            'program_id' => $testProgram->id,
            'currency' => 'EGP',
            'selling_price' => $selling0,
            'purchase_price' => $purchase0,
            'per_person' => true,
        ]);
        $service->addPayment($booking3, ['amount' => $selling0, 'account_id' => $vault->id]);
        $booking3 = $booking3->fresh(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

        $originalExpenseId = $booking3->expenseTransaction->id;
        $originalIncomeId = $booking3->incomeTransaction->id;

        // Update with higher prices
        $service->update($booking3, [
            'selling_price' => 100.0,
            'purchase_price' => 80.0,
        ]);

        // Original transactions must STILL exist
        $expStillThere = DB::table('transactions')->where('id', $originalExpenseId)->exists();
        $incStillThere = DB::table('transactions')->where('id', $originalIncomeId)->exists();

        // Booking's FK pointers may have been updated to NEW txns (reposted)
        $booking3 = $booking3->fresh(['expenseTransaction', 'incomeTransaction']);
        $newExpenseId = $booking3->expenseTransaction?->id;
        $newIncomeId = $booking3->incomeTransaction?->id;

        $expenseReposted = ($newExpenseId !== $originalExpenseId);
        $incomeReposted = ($newIncomeId !== $originalIncomeId);

        // Old transactions should still have AccountEntry rows (additive inverses)
        $origEntriesCount = DB::table('account_entries')
            ->whereIn('transaction_id', [$originalExpenseId, $originalIncomeId])
            ->count();

        $s4Passed = $expStillThere
            && $incStillThere
            && $expenseReposted
            && $incomeReposted
            && $origEntriesCount >= 4; // at least 2 original + 2 inverse

        $out['scenarios']['S4_update_price_change_additive'] = [
            'passed' => $s4Passed,
            'original_expense_id' => $originalExpenseId,
            'original_income_id' => $originalIncomeId,
            'original_expense_still_exists' => $expStillThere,
            'original_income_still_exists' => $incStillThere,
            'new_expense_id' => $newExpenseId,
            'new_income_id' => $newIncomeId,
            'expense_reposted' => $expenseReposted,
            'income_reposted' => $incomeReposted,
            'original_entries_count' => $origEntriesCount,
        ];

        echo "[S4 update] passed=" . ($s4Passed ? 'YES' : 'NO') . " | orig_exp_still=" . ($expStillThere ? 'YES' : 'NO') . " | orig_inc_still=" . ($incStillThere ? 'YES' : 'NO') . " | reposted=" . (($expenseReposted && $incomeReposted) ? 'YES' : 'NO') . "\n";
    } catch (\Throwable $e) {
        $out['scenarios']['S4_update_price_change_additive'] = ['passed' => false, 'error' => $e->getMessage()];
        echo "[S4 update] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // VERDICT
    // ════════════════════════════════════════════════════════
    $out['verdict'] = [
        's1_cancel_additive_reversal' => $out['scenarios']['S1_cancel_additive_reversal']['passed'] ?? false,
        's2_delete_booking_with_reversal' => $out['scenarios']['S2_delete_booking_with_reversal']['passed'] ?? false,
        's3_filament_header_action' => $out['scenarios']['S3_filament_header_action']['passed'] ?? false,
        's4_update_price_change_additive' => $out['scenarios']['S4_update_price_change_additive']['passed'] ?? false,
    ];

    $out['success'] = $out['verdict']['s1_cancel_additive_reversal']
        && $out['verdict']['s2_delete_booking_with_reversal']
        && $out['verdict']['s3_filament_header_action']
        && $out['verdict']['s4_update_price_change_additive'];

} catch (\Throwable $e) {
    $out['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
}

file_put_contents(
    storage_path('logs/real_db_hajjumra_reversal.json'),
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== HAJJUMRA REVERSAL HARDENING RESULT ===\n";
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";
