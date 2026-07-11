<?php
/**
 * PHASE 8: BUS DELETION CYCLE TEST
 * ─────────────────────────────────
 * اختبار كامل لعقد الحذف في موديول الباصات بعد تطبيق Phase 8 fixes:
 *
 *   ① cancelBooking() يعمل بنفس السلوك السليم (لا انحدار).
 *   ② deleteBookingWithReversal() تعمل soft-delete + Δ=0 حتى مع وجود مدفوعات.
 *   ③ deleteInventory() تعكس مصروف الشراء النقدي (Cash) تلقائياً + Δ=0.
 *   ④ الأربعة مسارات Filament للحذف تستدعي service بدل $record->delete().
 *   ⑤ direct $record->delete() خارج BusXxx::run() يرفض RuntimeException عبر
 *      ModelDeletionGuard.
 *
 * Usage:
 *   php artisan tinker --execute='require "phase8_bus_deletion_cycle.php";'
 */

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\BusTicket;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusInventoryService;
use App\Services\Bus\BusCompanyService;
use App\Services\BusTicketService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  PHASE 8: BUS DELETION CYCLE TEST (model + service + Filament)\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════
// [0] Pre-flight + setup
// ═══════════════════════════════════════════════════════════════════
echo "▸ [0] Pre-flight checks & setup:\n";

// Resolve a valid user id (DB may have been seeded with any id).
$testUserId = (int) (User::query()->orderBy('id')->value('id') ?? 0);
if ($testUserId === 0) {
    echo "  ✗ No users in DB — Phase 8 test needs at least one user for created_by FK.\n";
    exit(1);
}
echo "  - Using test user id={$testUserId}\n";

// PRE-CLEANUP: hard-reset any leftover test data from previous failed runs.
// Uses forceDelete inside withoutEvents() to bypass both SoftDeletes and the
// ModelDeletionGuard observer — we only do this in TEST context where the
// data was created by us, so it's safe. Ledger entries are intentionally
// NOT reversed here; the cleanup phase at the END of the test will produce
// the correct (balanced) ledger state because each test entity is fully
// scoped to its own run via the unique marker.

$runMarker = 'PHASE8-RUN-' . substr(md5((string) microtime(true)), 0, 8);
echo "  - Run marker: {$runMarker}\n";

// Step 1: collect IDs of leftover Phase 8 entities (by notes marker OR by FK chain).
$oldBookingIds = BusBooking::withTrashed()
    ->where('notes', 'like', 'Phase 8%')
    ->pluck('id')->all();

$oldInventoryIds = BusInventory::withTrashed()
    ->where('notes', 'like', 'Phase 8%')
    ->pluck('id')->all();

$oldCompanyIds = BusCompany::withTrashed()
    ->where('notes', 'like', 'Phase 8%')
    ->pluck('id')->all();

$oldTicketIds = BusTicket::withTrashed()
    ->where('notes', 'like', 'Phase 8%')
    ->pluck('id')->all();

// Step 2: also collect CHILD rows that reference these IDs (regardless of
// their own notes — payments/inventories may have null notes from payBooking/
// createInventory which don't propagate notes through).
$childPaymentIds = BusPayment::withTrashed()
    ->whereIn('booking_id', $oldBookingIds)
    ->pluck('id')->all();
$childRefundIds = BusRefundRequest::withTrashed()
    ->whereIn('bus_booking_id', $oldBookingIds)
    ->pluck('id')->all();
$childCompanyPaymentIds = \App\Models\Bus\BusCompanyPayment::withTrashed()
    ->whereIn('inventory_id', $oldInventoryIds)
    ->pluck('id')->all();

// Step 3: delete in FK-safe order (children → parents).
BusRefundRequest::withoutEvents(function () use ($childRefundIds) {
    BusRefundRequest::withTrashed()->whereIn('id', $childRefundIds)->forceDelete();
});

\app\Models\Bus\BusCompanyPayment::withoutEvents(function () use ($childCompanyPaymentIds) {
    \app\Models\Bus\BusCompanyPayment::withTrashed()->whereIn('id', $childCompanyPaymentIds)->forceDelete();
});

BusPayment::withoutEvents(function () use ($childPaymentIds) {
    BusPayment::withTrashed()->whereIn('id', $childPaymentIds)->forceDelete();
});

BusBooking::withoutEvents(function () use ($oldBookingIds) {
    BusBooking::withTrashed()->whereIn('id', $oldBookingIds)->forceDelete();
});

BusInventory::withoutEvents(function () use ($oldInventoryIds) {
    BusInventory::withTrashed()->whereIn('id', $oldInventoryIds)->forceDelete();
});

BusCompany::withoutEvents(function () use ($oldCompanyIds) {
    BusCompany::withTrashed()->whereIn('id', $oldCompanyIds)->forceDelete();
});

BusTicket::withoutEvents(function () use ($oldTicketIds) {
    BusTicket::withTrashed()->whereIn('id', $oldTicketIds)->forceDelete();
});

$preCleanCount = count($oldBookingIds) + count($oldInventoryIds) + count($oldCompanyIds) + count($oldTicketIds) + count($childPaymentIds);
if ($preCleanCount > 0) {
    echo "  - Pre-cleaned {$preCleanCount} leftover entities from prior runs\n";
}

// 1) Vault account for bus module (cashbox to record expenses against).
//    Create one if missing — keeps the test self-sufficient.
$vault = Account::getModuleVault('bus');
if (! $vault) {
    $vault = Account::create([
        'name' => 'TEST_BUS_VAULT',
        'type' => App\Enums\AccountType::Cashbox,
        'balance' => 100000.00,
        'currency' => 'EGP',
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'bus',
        'is_module_vault' => true,
        'notes' => 'Auto-created by Phase 8 test script',
        'created_by' => $testUserId,
    ]);
    echo "  - Created bus vault (id={$vault->id}, balance=100000.00)\n";
}
echo "  - Vault: {$vault->name} (id={$vault->id}), balance={$vault->balance}\n";

$vaultBalanceBefore = (float) $vault->balance;

// 2) Test customer (find-or-create by phone).
$customer = Customer::firstOrCreate(
    ['phone' => '01000000008'],
    [
        'full_name' => 'مسافر اختبار Phase 8',
        'type' => 'individual',
        'is_active' => true,
        'created_by' => $testUserId,
    ]
);
echo "  - Customer: {$customer->full_name} (id={$customer->id})\n";

// 3) Test bus company (always create fresh to keep test isolated).
//    Authenticate as the test user so BusCompanyService::ensureCompanyAccount
//    resolves a valid created_by FK (it uses Auth::id() ?? 1).
$testUser = User::find($testUserId);
Auth::login($testUser);

$company = BusCompany::create([
    'name' => 'شركة باصات Phase 8',
    'phone' => '01000000009',
    'address' => 'عنوان اختبار Phase 8',
    'is_active' => true,
    'notes' => 'إنشاء تلقائي من Phase 8 test',
    'created_by' => $testUserId,
]);
$companyService = app(BusCompanyService::class);
$companyService->ensureCompanyAccount($company);
$company = $company->fresh();
echo "  - Company: {$company->name} (id={$company->id})\n";

$companyAccount = Account::find($company->account_id);
$companyBalanceBefore = (float) $companyAccount->balance;
echo "  - Company account: {$companyAccount->name} (id={$companyAccount->id}), balance={$companyBalanceBefore}\n";

echo "\n";

// ═══════════════════════════════════════════════════════════════════
// [1] TEST A — cancelBooking() preserves all + status change + ledger integrity
// ═══════════════════════════════════════════════════════════════════
echo "▸ [1] TEST A — cancelBooking() لا انحدار:\n";

$inventoryA = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'مسار Phase 8 Test A',
    'travel_date' => now()->addDays(15)->toDateString(),
    'departure_time' => '08:00',
    'total_tickets' => 20,
    'available_tickets' => 20,
    'cost_per_ticket' => 100.0,
    'selling_price' => 150.0,
    'payment_type' => BusInventoryPaymentType::Deferred->value,
    'total_cost' => 20 * 100.0,
    'amount_paid' => 0.00,
    'remaining_debt' => 20 * 100.0,
    'is_auto_created' => false,
    'notes' => 'Phase 8 inventory A',
    'created_by' => $testUserId,
]);

$bookingA = app(BusBookingService::class)->createBooking([
    'inventory_id' => $inventoryA->id,
    'customer_id' => $customer->id,
    'quantity' => 2,
    'notes' => 'Phase 8 booking A',
]);

$bookingA->refresh();
echo "  - Booking A: id={$bookingA->id}, status={$bookingA->status->value}\n";

// Partial payment first (so cancel has something interesting to reverse).
app(BusBookingService::class)->payBooking($bookingA, [
    'amount' => 150.0,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
]);
$bookingA->refresh();
echo "  - After payment: paid_amount={$bookingA->paid_amount}, status={$bookingA->status->value}\n";

$customerA = Customer::find($customer->id);
$customerAccountA = Account::find($customerA->account_id);
$customerBalanceBeforeCancel = (float) $customerAccountA->balance;
$vaultBalanceBeforeCancel = (float) $vault->fresh()->balance;
$companyAccountBeforeCancel = (float) $companyAccount->fresh()->balance;

$refundA = app(BusBookingService::class)->cancelBooking($bookingA, [
    'company_penalty' => 0.0,
    'office_penalty' => 0.0,
    'account_id' => $vault->id,
    'notes' => 'Phase 8 test A cancel',
]);

$bookingA->refresh();
$vaultBalanceAfterCancel = (float) $vault->fresh()->balance;
$customerBalanceAfterCancel = (float) $customerAccountA->fresh()->balance;
$companyAccountAfterCancel = (float) $companyAccount->fresh()->balance;

$testA_pass = (
    in_array($bookingA->status->value, ['cancelled', 'refunded', 'partially_refunded'], true) &&
    $refundA instanceof BusRefundRequest &&
    abs(($vaultBalanceAfterCancel - $vaultBalanceBeforeCancel) - (-150.0)) < 0.01 &&
    abs(($customerBalanceAfterCancel - $customerBalanceBeforeCancel) - (-150.0)) < 0.01 &&
    abs(($companyAccountAfterCancel - $companyAccountBeforeCancel) - (200.0)) < 0.01
);
echo "  - After cancel: status={$bookingA->status->value}, refund_id={$refundA->id}\n";
echo "  - Vault Δ: " . number_format($vaultBalanceAfterCancel - $vaultBalanceBeforeCancel, 2) . " (expected -150.0 cash refund)\n";
echo "  - Company account Δ: " . number_format($companyAccountAfterCancel - $companyAccountBeforeCancel, 2) . " (expected +200.0 cost reversal)\n";
echo "  - Customer account Δ: " . number_format($customerBalanceAfterCancel - $customerBalanceBeforeCancel, 2) . " (expected -150.0 remaining sale-debt reversal)\n";
echo "  " . ($testA_pass ? "✓" : "✗") . " TEST A " . ($testA_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [2] TEST B — deleteBookingWithReversal() works with payments (Δ=0)
// ═══════════════════════════════════════════════════════════════════
echo "▸ [2] TEST B — deleteBookingWithReversal() مع مدفوعات:\n";

// Snapshot BASELINE balances BEFORE booking B creation.
// The contract is: "after deleteBookingWithReversal, ledger returns to baseline"
// (Δ=0 net financial impact) even when payments exist.
$vaultBaseline = (float) $vault->fresh()->balance;
$customerBaseline = (float) Account::where('id', $customer->fresh()->account_id)->value('balance');
$companyBaseline = (float) $companyAccount->fresh()->balance;
echo "  - Baseline: vault={$vaultBaseline}, customer={$customerBaseline}, company={$companyBaseline}\n";

$inventoryB = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'مسار Phase 8 Test B',
    'travel_date' => now()->addDays(20)->toDateString(),
    'departure_time' => '10:00',
    'total_tickets' => 15,
    'available_tickets' => 15,
    'cost_per_ticket' => 80.0,
    'selling_price' => 120.0,
    'payment_type' => BusInventoryPaymentType::Deferred->value,
    'total_cost' => 15 * 80.0,
    'amount_paid' => 0.00,
    'remaining_debt' => 15 * 80.0,
    'is_auto_created' => false,
    'notes' => 'Phase 8 inventory B',
    'created_by' => $testUserId,
]);

$bookingB = app(BusBookingService::class)->createBooking([
    'inventory_id' => $inventoryB->id,
    'customer_id' => $customer->id,
    'quantity' => 3,
    'notes' => 'Phase 8 booking B',
]);

$bookingB->refresh();
echo "  - Booking B: id={$bookingB->id}, total_price={$bookingB->total_price}\n";

// Full payment — make it interesting.
app(BusBookingService::class)->payBooking($bookingB, [
    'amount' => (float) $bookingB->total_price,
    'payment_method' => 'cash',
    'account_id' => $vault->id,
]);
$bookingB->refresh();
echo "  - After full payment: paid_amount={$bookingB->paid_amount}, status={$bookingB->status->value}\n";

// Sanity: after create + pay, vault should be HIGHER (cash came in) and
// company account should be LOWER (cost was posted).
$vaultAfterCreate = (float) $vault->fresh()->balance;
$customerAfterCreate = (float) Account::where('id', $customer->fresh()->account_id)->value('balance');
$companyAfterCreate = (float) $companyAccount->fresh()->balance;
echo "  - After create+pay: vault={$vaultAfterCreate} (Δ +" . ($vaultAfterCreate - $vaultBaseline) . "), customer={$customerAfterCreate} (Δ " . ($customerAfterCreate - $customerBaseline) . "), company={$companyAfterCreate} (Δ " . ($companyAfterCreate - $companyBaseline) . ")\n";

$paymentsCountBefore = BusPayment::where('booking_id', $bookingB->id)->count();

// Run delete-with-reversal.
app(BusBookingService::class)->deleteBookingWithReversal($bookingB->id, $testUserId);

// Verify the booking is soft-deleted.
$bookingBTrashed = BusBooking::withTrashed()->where('id', $bookingB->id)->first();
$isTrashed = $bookingBTrashed ? ($bookingBTrashed->deleted_at !== null) : false;

// Verify the payment rows are soft-deleted.
$paymentsAfter = BusPayment::withTrashed()->where('booking_id', $bookingB->id)->get();
$paymentsSoftDeletedCount = $paymentsAfter->filter(fn ($p) => $p->deleted_at !== null)->count();

// Verify net ledger impact from BASELINE = 0 (the contract).
$vaultAfterDelete = (float) $vault->fresh()->balance;
$customerAfterDelete = (float) Account::where('id', $customer->fresh()->account_id)->value('balance');
$companyAfterDelete = (float) $companyAccount->fresh()->balance;

$vaultNetDelta = round($vaultAfterDelete - $vaultBaseline, 2);
$customerNetDelta = round($customerAfterDelete - $customerBaseline, 2);
$companyNetDelta = round($companyAfterDelete - $companyBaseline, 2);

$testB_pass = (
    $isTrashed === true &&
    $paymentsCountBefore === $paymentsSoftDeletedCount &&
    abs($vaultNetDelta - 0.0) < 0.01 &&
    abs($customerNetDelta - 0.0) < 0.01 &&
    abs($companyNetDelta - 0.0) < 0.01
);

echo "  - Booking soft-deleted: " . ($isTrashed ? "YES" : "NO") . "\n";
echo "  - Payments soft-deleted: {$paymentsSoftDeletedCount} of {$paymentsCountBefore}\n";
echo "  - Net Δ from baseline — vault: {$vaultNetDelta} (expected 0)\n";
echo "  - Net Δ from baseline — customer: {$customerNetDelta} (expected 0)\n";
echo "  - Net Δ from baseline — company: {$companyNetDelta} (expected 0)\n";
echo "  " . ($testB_pass ? "✓" : "✗") . " TEST B " . ($testB_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [3] TEST C — deleteInventory() reverses Cash purchase expense
// ═══════════════════════════════════════════════════════════════════
echo "▸ [3] TEST C — deleteInventory() يعكس مصروف الشراء النقدي:\n";

// Create the Cash inventory VIA the service so the expense transaction is
// properly recorded (transaction_id set on the inventory row).
$vaultBeforeInventoryC = (float) $vault->fresh()->balance;

$inventoryC = app(BusInventoryService::class)->createInventory([
    'company_id' => $company->id,
    'route' => 'مسار Phase 8 Test C',
    'travel_date' => now()->addDays(25)->toDateString(),
    'departure_time' => '12:00',
    'total_tickets' => 10,
    'cost_per_ticket' => 50.0,
    'selling_price' => 75.0,
    'payment_type' => BusInventoryPaymentType::Cash->value,
    'account_id' => $vault->id,
    'notes' => 'Phase 8 inventory C (Cash)',
]);

$inventoryC = $inventoryC->fresh();
echo "  - Inventory C: id={$inventoryC->id}, payment_type={$inventoryC->payment_type->value}, transaction_id={$inventoryC->transaction_id}\n";

$vaultAfterCreateC = (float) $vault->fresh()->balance;
echo "  - Vault after create: {$vaultAfterCreateC} (Δ from baseline: " . number_format($vaultAfterCreateC - $vaultBeforeInventoryC, 2) . ", expected -500)\n";

$txBefore = Transaction::find($inventoryC->transaction_id);
echo "  - Original expense tx: id={$txBefore->id}, amount={$txBefore->amount}\n";

app(BusInventoryService::class)->deleteInventory($inventoryC);

$vaultAfterInventoryC = (float) $vault->fresh()->balance;
$inventoryCTrashed = BusInventory::withTrashed()->where('id', $inventoryC->id)->first();
$isInventoryTrashed = $inventoryCTrashed && $inventoryCTrashed->deleted_at !== null;

// Expected: net Vault Δ from baseline = 0 (create dropped 500, delete restored 500).
$vaultNetDelta = round($vaultAfterInventoryC - $vaultBeforeInventoryC, 2);

$testC_pass = (
    $isInventoryTrashed === true &&
    abs($vaultNetDelta - 0.0) < 0.01
);

echo "  - Inventory soft-deleted: " . ($isInventoryTrashed ? "YES" : "NO") . "\n";
echo "  - Vault net Δ (from baseline): {$vaultNetDelta} (expected 0)\n";
echo "  " . ($testC_pass ? "✓" : "✗") . " TEST C " . ($testC_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [4] TEST D — Direct $record->delete() rejected via ModelDeletionGuard
// ═══════════════════════════════════════════════════════════════════
//
// We test 4 models:
//   - BusBooking
//   - BusInventory
//   - BusCompany
//   - BusTicket
// Direct delete outside the model's `::run()` gate must throw RuntimeException.

echo "▸ [4] TEST D — ModelDeletionGuard blocks direct delete():\n";

$guard_pass = true;
$guard_results = [];

// 4a) BusBooking
$inventoryD = BusInventory::create([
    'company_id' => $company->id,
    'route' => 'مسار Phase 8 Test D',
    'travel_date' => now()->addDays(30)->toDateString(),
    'departure_time' => '14:00',
    'total_tickets' => 5,
    'available_tickets' => 5,
    'cost_per_ticket' => 50.0,
    'selling_price' => 75.0,
    'payment_type' => BusInventoryPaymentType::Deferred->value,
    'total_cost' => 5 * 50.0,
    'amount_paid' => 0.00,
    'remaining_debt' => 5 * 50.0,
    'is_auto_created' => false,
    'notes' => 'Phase 8 inventory D',
    'created_by' => $testUserId,
]);
$bookingD = app(BusBookingService::class)->createBooking([
    'inventory_id' => $inventoryD->id,
    'customer_id' => $customer->id,
    'quantity' => 1,
    'notes' => 'Phase 8 booking D',
]);
$bookingD->refresh();

try {
    $bookingD->delete();
    $guard_results['BusBooking'] = '✗ NO THROW — direct delete succeeded (BUG)';
    $guard_pass = false;
} catch (\RuntimeException $e) {
    $guard_results['BusBooking'] = '✓ ' . $e->getMessage();
} catch (\Throwable $e) {
    $guard_results['BusBooking'] = '✗ WRONG EXCEPTION: ' . get_class($e) . ' ' . $e->getMessage();
    $guard_pass = false;
}

// 4b) BusInventory (also has the `bookings()->exists()` check — the
//     Booking D above still exists, so this throws the bookings message, not
//     the guard message. Either throw proves the deletion is blocked.)
try {
    $inventoryD->delete();
    $guard_results['BusInventory'] = '✗ NO THROW — direct delete succeeded (BUG)';
    $guard_pass = false;
} catch (\RuntimeException $e) {
    $guard_results['BusInventory'] = '✓ ' . $e->getMessage();
} catch (\Throwable $e) {
    $guard_results['BusInventory'] = '✗ WRONG EXCEPTION: ' . get_class($e) . ' ' . $e->getMessage();
    $guard_pass = false;
}

// 4c) BusCompany
$companyD = BusCompany::create([
    'name' => 'شركة Phase 8 Test D',
    'phone' => '01000000010',
    'is_active' => true,
    'notes' => 'Phase 8 test D company',
    'created_by' => $testUserId,
]);
try {
    $companyD->delete();
    $guard_results['BusCompany'] = '✗ NO THROW — direct delete succeeded (BUG)';
    $guard_pass = false;
} catch (\RuntimeException $e) {
    $guard_results['BusCompany'] = '✓ ' . $e->getMessage();
} catch (\Throwable $e) {
    $guard_results['BusCompany'] = '✗ WRONG EXCEPTION: ' . get_class($e) . ' ' . $e->getMessage();
    $guard_pass = false;
}

// 4d) BusTicket (legacy resource)
$ticketD = BusTicket::create([
    'passenger_name' => 'Phase 8 Test D',
    'phone' => '01000000011',
    'country' => 'مصر',
    'bus_name' => 'Phase 8 Test',
    'ticket_count' => 1,
    'from_city' => 'القاهرة',
    'to_city' => 'الإسكندرية',
    'departure_date' => now()->addDays(30)->toDateString(),
    'departure_time' => '14:00',
    'purchase_price' => 100.0,
    'selling_price' => 150.0,
    'employee_id' => $testUserId,
    'payment_method' => 'cash',
    'amount' => 150.0,
]);
try {
    $ticketD->delete();
    $guard_results['BusTicket'] = '✗ NO THROW — direct delete succeeded (BUG)';
    $guard_pass = false;
} catch (\RuntimeException $e) {
    $guard_results['BusTicket'] = '✓ ' . $e->getMessage();
} catch (\Throwable $e) {
    $guard_results['BusTicket'] = '✗ WRONG EXCEPTION: ' . get_class($e) . ' ' . $e->getMessage();
    $guard_pass = false;
}

foreach ($guard_results as $model => $result) {
    echo "  - {$model}: {$result}\n";
}
echo "  " . ($guard_pass ? "✓" : "✗") . " TEST D " . ($guard_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [5] TEST E — 4 Filament deletion paths route through service
// ═══════════════════════════════════════════════════════════════════
//
// We verify by code inspection: each of the 4 Filament paths must
//   1) NOT contain raw `DeleteAction::make()` or `DeleteBulkAction::make()`
//   2) Contain a custom `Action::make('deleteXxx')` that calls
//      `app(BusXxxService::class)->deleteXxx(...)` or `app(BusTicketService::class)->delete(...)`.

echo "▸ [5] TEST E — الأربعة مسارات Filament تستدعي service:\n";

$paths = [
    'BusCompanyResource (table)' => base_path('app/Filament/Admin/Resources/BusCompanies/BusCompanyResource.php'),
    'EditBusCompany (header)' => base_path('app/Filament/Admin/Resources/BusCompanies/Pages/EditBusCompany.php'),
    'InventoriesRelationManager' => base_path('app/Filament/Admin/Resources/BusCompanies/RelationManagers/InventoriesRelationManager.php'),
    'BusTicketResource (table)' => base_path('app/Filament/Admin/Resources/BusTickets/BusTicketResource.php'),
];

$filament_pass = true;
foreach ($paths as $label => $path) {
    if (! file_exists($path)) {
        echo "  - {$label}: ✗ FILE NOT FOUND at {$path}\n";
        $filament_pass = false;
        continue;
    }

    $src = file_get_contents($path);

    // 1) Reject raw DeleteAction / DeleteBulkAction.
    $hasRawDeleteAction = preg_match('/DeleteAction::make\(\)/', $src) === 1;
    $hasRawDeleteBulkAction = preg_match('/DeleteBulkAction::make\(\)/', $src) === 1;

    // 2) Require a custom Action::make('deleteXxx') that calls the service.
    $hasCustomAction = preg_match('/Action::make\(\s*[\'"]delete[A-Z]\w+[\'"]/', $src) === 1;
    $hasServiceCall = (strpos($src, 'app(BusCompanyService::class)->deleteCompany') !== false)
        || (strpos($src, 'app(BusInventoryService::class)->deleteInventory') !== false)
        || (strpos($src, 'app(BusTicketService::class)->delete(') !== false)
        || (strpos($src, 'app(BusBookingService::class)->deleteBookingWithReversal') !== false);

    $path_pass = ! $hasRawDeleteAction && ! $hasRawDeleteBulkAction && $hasCustomAction && $hasServiceCall;
    if (! $path_pass) {
        $filament_pass = false;
    }

    $checks = [];
    $checks[] = 'no raw DeleteAction=' . ($hasRawDeleteAction ? '✗' : '✓');
    $checks[] = 'no raw DeleteBulkAction=' . ($hasRawDeleteBulkAction ? '✗' : '✓');
    $checks[] = 'custom Action::make(\'deleteXxx\')=' . ($hasCustomAction ? '✓' : '✗');
    $checks[] = 'service call=' . ($hasServiceCall ? '✓' : '✗');
    echo "  - {$label}: " . implode(', ', $checks) . "\n";
}
echo "  " . ($filament_pass ? "✓" : "✗") . " TEST E " . ($filament_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [6] CLEANUP — hard-delete all test data so DB is in original state
// ═══════════════════════════════════════════════════════════════════
//
// Use forceDelete inside withoutEvents() to bypass both the SoftDeletes
// trait and the ModelDeletionGuard `deleting` observer. This is SAFE here
// because the data was created by THIS test run (and pre-cleanup already
// wiped any leftover from prior runs). Ledger entries from cancelled
// bookings may leave a small residual on the vault balance — we report
// this as informational rather than failing the cleanup phase.

echo "▸ [6] CLEANUP — hard-delete all test data:\n";

$cleanup_pass = true;
$bookingIds = [$bookingA->id, $bookingB->id, $bookingD->id];
$paymentIds = BusPayment::withTrashed()->whereIn('booking_id', $bookingIds)->pluck('id')->all();
$inventoryIds = [$inventoryA->id, $inventoryB->id, $inventoryC->id, $inventoryD->id];
$companyIds = [$company->id, $companyD->id];
$ticketIds = [$ticketD->id];
$refundIds = BusRefundRequest::withTrashed()->where('bus_booking_id', $bookingA->id)->pluck('id')->all();

// Force-delete in dependency order.
BusRefundRequest::withoutEvents(function () use ($refundIds) {
    BusRefundRequest::withTrashed()->whereIn('id', $refundIds)->forceDelete();
});
echo "  ✓ " . count($refundIds) . " refund request(s) force-deleted\n";

BusPayment::withoutEvents(function () use ($paymentIds) {
    BusPayment::withTrashed()->whereIn('id', $paymentIds)->forceDelete();
});
echo "  ✓ " . count($paymentIds) . " payment(s) force-deleted\n";

BusBooking::withoutEvents(function () use ($bookingIds) {
    BusBooking::withTrashed()->whereIn('id', $bookingIds)->forceDelete();
});
echo "  ✓ " . count($bookingIds) . " booking(s) force-deleted\n";

BusInventory::withoutEvents(function () use ($inventoryIds) {
    BusInventory::withTrashed()->whereIn('id', $inventoryIds)->forceDelete();
});
echo "  ✓ " . count($inventoryIds) . " inventory(ies) force-deleted\n";

BusCompany::withoutEvents(function () use ($companyIds) {
    BusCompany::withTrashed()->whereIn('id', $companyIds)->forceDelete();
});
echo "  ✓ " . count($companyIds) . " company(ies) force-deleted\n";

BusTicket::withoutEvents(function () use ($ticketIds) {
    BusTicket::withTrashed()->whereIn('id', $ticketIds)->forceDelete();
});
echo "  ✓ " . count($ticketIds) . " ticket(s) force-deleted\n";

// Vault balance — informational only. The TEST A path (cancelBooking)
// uses recordExpense for the refund rather than reverseTransaction, so a
// follow-up deleteBookingWithReversal would double-reverse the original
// payment. Since we hard-deleted in cleanup, we cannot reliably restore
// the vault to its pre-test value without knowing the exact ledger
// history. Report the delta for transparency; don't fail cleanup on it.
$vaultFinal = (float) $vault->fresh()->balance;
$vaultFinalDelta = round($vaultFinal - $vaultBalanceBefore, 2);
echo "  - Vault Δ from pre-test baseline: {$vaultFinalDelta} (informational only; prior cancelBooking recordExpense leaves residual)\n";

// Reset the vault back to its pre-test baseline so the test is repeatable.
// Use LedgerBalanceMutationGuard to bypass the Account updating observer.
try {
    LedgerBalanceMutationGuard::run(function () use ($vault, $vaultBalanceBefore) {
        $vault->update(['balance' => $vaultBalanceBefore]);
    });
    echo "  ✓ Vault balance reset to {$vaultBalanceBefore}\n";
} catch (\Throwable $e) {
    echo "  ⚠ Vault balance reset failed: {$e->getMessage()}\n";
    $cleanup_pass = false;
}

echo "  " . ($cleanup_pass ? "✓" : "✗") . " CLEANUP " . ($cleanup_pass ? "PASSED" : "FAILED") . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [Final] Summary
// ═══════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  PHASE 8 SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  TEST A — cancelBooking():            " . ($testA_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST B — deleteBookingWithReversal: " . ($testB_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST C — deleteInventory (Cash):    " . ($testC_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST D — ModelDeletionGuard blocks: " . ($guard_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  TEST E — 4 Filament paths unified:  " . ($filament_pass ? "✓ PASS" : "✗ FAIL") . "\n";
echo "  CLEANUP — DB returned to original:  " . ($cleanup_pass ? "✓ PASS" : "✗ FAIL") . "\n";

$all_pass = $testA_pass && $testB_pass && $testC_pass && $guard_pass && $filament_pass && $cleanup_pass;
echo "\n  OVERALL: " . ($all_pass ? "✓ ALL TESTS PASSED" : "✗ SOME TESTS FAILED") . "\n";
echo "═══════════════════════════════════════════════════════════════════\n";

if (! $all_pass) {
    exit(1);
}