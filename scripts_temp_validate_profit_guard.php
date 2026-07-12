<?php

/**
 * Phase 5 — Real DB Validation: Profit-Column Guard.
 *
 * Methodology (mirrors scripts_temp_real_db_validation.php):
 *   - Use the LOCAL MySQL DB (`safarakealayna` on 127.0.0.1).
 *   - For each test: DB::beginTransaction → setup → assert → DB::rollBack.
 *   - RollBack ALWAYS — no data persists.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$pass = 0;
$fail = 0;

function header_line(string $s): void { echo "\n" . str_repeat('═', 80) . "\n  {$s}\n" . str_repeat('═', 80) . "\n"; }
function pass(string $label): void { global $pass; echo "  ✅ {$label}\n"; $pass++; }
function fail(string $label, string $detail = ''): void { global $fail; echo "  ❌ {$label}\n"; if ($detail !== '') echo "      {$detail}\n"; $fail++; }

function ensureUser(string $email): int {
    static $cache = [];
    if (isset($cache[$email])) return $cache[$email];
    $id = (int) DB::table('users')->insertGetId([
        'name' => 'GUARD ' . substr(md5($email), 0, 6),
        'email' => $email,
        'password' => Hash::make('secret'),
        'role' => 'employee',
        'travel_alert_days_before' => 3,
        'travel_alert_time' => '08:00:00',
        'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $cache[$email] = $id;
    return $id;
}

function ensureEmployee(string $email): int {
    static $cache = [];
    if (isset($cache[$email])) return $cache[$email];
    $userId = ensureUser($email);
    $id = (int) DB::table('employees')->insertGetId([
        'user_id' => $userId,
        'first_name' => 'P', 'last_name' => 'G', 'full_name' => 'PROFIT GUARD',
        'status' => 'active', 'employment_type' => 'full_time', 'employment_status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $cache[$email] = $id;
    return $id;
}

function ensureCustomer(string $suffix): int {
    static $cache = [];
    if (isset($cache[$suffix])) return $cache[$suffix];
    $id = (int) DB::table('customers')->insertGetId([
        'name' => 'GUARD-' . $suffix, 'full_name' => 'GUARD-' . $suffix,
        'phone' => '0000' . substr(md5($suffix . uniqid()), 0, 6),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $cache[$suffix] = $id;
    return $id;
}

function ensureAccount(string $suffix = ''): int {
    // Don't cache across tests — earlier tests rollback their own inserts.
    return (int) DB::table('accounts')->insertGetId([
        'name' => 'GUARD-ACC-' . $suffix . '-' . substr(md5(uniqid()), 0, 6),
        'type' => 'cashbox', 'owner_type' => 'office', 'module_type' => 'office',
        'currency' => 'EGP', 'balance' => 0, 'is_active' => 1, 'is_module_vault' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ensureBusInventory(): int {
    static $id = null;
    if ($id !== null) return $id;
    $userId = ensureUser('guard-bus-co-' . substr(md5(uniqid()), 0, 4) . '@t.local');
    $companyId = (int) DB::table('bus_companies')->insertGetId([
        'name' => 'GUARD-CO-' . substr(md5(uniqid()), 0, 6),
        'is_active' => 1, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = (int) DB::table('bus_inventories')->insertGetId([
        'company_id' => $companyId, 'route' => 'Cairo-Alex',
        'travel_date' => now()->addDays(7)->toDateString(),
        'total_tickets' => 50, 'available_tickets' => 50,
        'cost_per_ticket' => 100, 'selling_price' => 150,
        'payment_type' => 'cash', 'total_cost' => 5000, 'amount_paid' => 0, 'remaining_debt' => 5000,
        'is_auto_created' => 0, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function ensureProgram(): int {
    static $id = null;
    if ($id !== null) return $id;
    $id = (int) DB::table('programs')->insertGetId([
        'program_name' => 'GUARD-PROG-' . substr(md5(uniqid()), 0, 6),
        'program_type' => 'hajj', 'total_nights' => 7,
        'mecca_hotel_name' => 'Test Mecca', 'mecca_nights' => 4,
        'departure_date' => now()->addDays(30)->toDateString(),
        'return_date' => now()->addDays(37)->toDateString(),
        'airline' => 'TestAir', 'departure_point' => 'Cairo', 'booking_status' => 'open',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function ensureOnlineServiceType(): int {
    static $id = null;
    if ($id !== null) return $id;
    $id = (int) DB::table('online_service_types')->insertGetId([
        'code' => 'guard_' . substr(md5(uniqid()), 0, 8),
        'name_ar' => 'G', 'name_en' => 'G', 'color' => '#000', 'is_active' => 1, 'order' => 999,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function ensureOnlineServiceProvider(): int {
    static $id = null;
    if ($id !== null) return $id;
    $id = (int) DB::table('online_service_providers')->insertGetId([
        'code' => 'guard_' . substr(md5(uniqid()), 0, 8),
        'name_ar' => 'G', 'name_en' => 'G', 'color' => '#000', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

function ensureVisaDetail(): int {
    static $id = null;
    if ($id !== null) return $id;
    $id = (int) DB::table('visa_details')->insertGetId([
        'visa_type' => 'tourist', 'country' => 'SA', 'duration' => '30d', 'status' => 'submitted',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return $id;
}

// ═══════════════════════════════════════════════════════════════════════════
// 1) FLIGHT BOOKING
// ═══════════════════════════════════════════════════════════════════════════
header_line('1) FlightBooking — direct write REJECTED, wrapped write ALLOWED');
DB::beginTransaction();
try {
    $customerId = ensureCustomer('FLIGHT');
    $employeeId = ensureEmployee('guard-flight@t.local');
    $userId = ensureUser('guard-flight@t.local');
    $accountId = ensureAccount('FLIGHT');

    $bookingId = (int) DB::table('flight_bookings')->insertGetId([
        'customer_id' => $customerId, 'employee_id' => $employeeId,
        'booking_reference' => 'GRD-' . substr(md5(uniqid()), 0, 8),
        'booking_number' => 'GRD-' . substr(md5(uniqid()), 0, 8),
        'booking_channel_type' => 'sign', 'booking_channel_provider' => 'SIGN',
        'pnr' => null, 'airline_name' => 'Manual', 'airline' => 'Manual',
        'origin' => 'CAI', 'destination' => 'DXB',
        'departure_date' => now()->addDays(7)->toDateString(),
        'departure_time' => '08:00:00', 'trip_type' => 'one_way', 'passenger_count' => 1,
        'purchase_price' => 1000, 'selling_price' => 1200, 'profit' => 200,
        'currency' => 'EGP', 'purchase_price_egp' => 1000, 'status' => 'pending',
        'agent_name' => 'Test', 'account_id' => $accountId, 'created_by' => $userId,
        'original_currency' => 'EGP', 'original_amount' => 1200, 'booking_exchange_rate' => 1.0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = \App\Models\Flight\FlightBooking::findOrFail($bookingId);

    // BLOCKED write
    $blocked = false; $blockedMsg = '';
    try { $booking->profit = 999; $booking->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('FlightBooking: direct $booking->profit=X; $booking->save() throws the guard');
    } else { fail('FlightBooking: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    // ALLOWED write (wrapped in ::runProfitMutation)
    \App\Models\Flight\FlightBooking::runProfitMutation(function () use ($booking) {
        $booking->profit = 300; $booking->save();
    });
    $booking->refresh();
    if ((float) $booking->profit === 300.0) {
        pass('FlightBooking: FlightBooking::runProfitMutation(...) wrap allows canonical write (profit=300)');
    } else { fail('FlightBooking: wrapped write did not persist correct value', 'got=' . $booking->profit); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('FlightBooking: unexpected exception', $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 2) BUS BOOKING
// ═══════════════════════════════════════════════════════════════════════════
header_line('2) BusBooking — direct write REJECTED, wrapped write ALLOWED');
DB::beginTransaction();
try {
    $customerId = ensureCustomer('BUS');
    $employeeId = ensureEmployee('guard-bus@t.local');
    $userId = ensureUser('guard-bus@t.local');
    $inventoryId = ensureBusInventory();

    $bookingId = (int) DB::table('bus_bookings')->insertGetId([
        'inventory_id' => $inventoryId, 'customer_id' => $customerId, 'employee_id' => $employeeId,
        'quantity' => 1, 'unit_price' => 150, 'total_price' => 150, 'paid_amount' => 0,
        'payment_status' => 'pending', 'profit' => 50, 'status' => 'pending',
        'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = \App\Models\Bus\BusBooking::findOrFail($bookingId);

    $blocked = false; $blockedMsg = '';
    try { $booking->profit = 999; $booking->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('BusBooking: direct write throws the guard');
    } else { fail('BusBooking: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    \App\Models\Bus\BusBooking::runProfitMutation(function () use ($booking) {
        $booking->profit = 75; $booking->save();
    });
    $booking->refresh();
    if ((float) $booking->profit === 75.0) {
        pass('BusBooking: BusBooking::runProfitMutation(...) wrap allows canonical write (profit=75)');
    } else { fail('BusBooking: wrapped write did not persist', 'got=' . $booking->profit); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('BusBooking: unexpected exception', $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 3) BUS TICKET (auto-compute model)
// ═══════════════════════════════════════════════════════════════════════════
header_line('3) BusTicket — auto-compute observer works, direct write REJECTED');
DB::beginTransaction();
try {
    $userId = ensureUser('guard-busticket@t.local');
    $ticket = \App\Models\BusTicket::runProfitMutation(function () use ($userId) {
        return \App\Models\BusTicket::create([
            'passenger_name' => 'GUARD-TEST', 'phone' => '0000', 'country' => 'EG',
            'bus_name' => 'TestBus', 'ticket_count' => 2,
            'from_city' => 'Cairo', 'to_city' => 'Giza',
            'departure_date' => now()->toDateString(), 'departure_time' => '10:00',
            'purchase_price' => 100, 'selling_price' => 200,
            'employee_id' => $userId, 'payment_method' => 'cash', 'amount' => 400,
        ]);
    });

    if ((float) $ticket->profit === 200.0) {
        pass('BusTicket: auto-compute observer (saving in ::runProfitMutation()) produced profit=200');
    } else { fail('BusTicket: auto-compute produced wrong value', 'got=' . $ticket->profit); }

    $blocked = false; $blockedMsg = '';
    try { $ticket->profit = 999; $ticket->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('BusTicket: direct write throws the guard');
    } else { fail('BusTicket: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('BusTicket: unexpected exception', $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 4) HAJJ UMRA BOOKING
// ═══════════════════════════════════════════════════════════════════════════
header_line('4) HajjUmraBooking — direct write REJECTED, wrapped write ALLOWED');
DB::beginTransaction();
try {
    $customerId = ensureCustomer('HAJJ');
    // HajjUmraBooking.employee_id FK → users (not employees).
    $userId = ensureUser('guard-hajj@t.local');
    $programId = ensureProgram();
    $accountId = ensureAccount('HAJJ');

    $bookingId = (int) DB::table('hajj_umra_bookings')->insertGetId([
        'customer_id' => $customerId, 'program_id' => $programId,
        'module' => 'hajj_umra',
        'purchase_price' => 1000, 'companion_purchase_price' => 0,
        'selling_price' => 1500, 'companion_selling_price' => 0, 'profit' => 500,
        'currency' => 'EGP', 'per_person' => 1, 'accommodation_choice' => 'standard',
        'agent_name' => 'GUARD', 'status' => 'confirmed',
        'account_id' => $accountId, 'employee_id' => $userId, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = \App\Models\HajjUmraBooking::findOrFail($bookingId);

    $blocked = false; $blockedMsg = '';
    try { $booking->profit = 999; $booking->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('HajjUmraBooking: direct write throws the guard');
    } else { fail('HajjUmraBooking: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    \App\Models\HajjUmraBooking::runProfitMutation(function () use ($booking) {
        $booking->profit = 600; $booking->save();
    });
    $booking->refresh();
    if ((float) $booking->profit === 600.0) {
        pass('HajjUmraBooking: HajjUmraBooking::runProfitMutation(...) wrap allows canonical write (profit=600)');
    } else { fail('HajjUmraBooking: wrapped write did not persist', 'got=' . $booking->profit); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('HajjUmraBooking: unexpected exception', $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 5) ONLINE TRANSACTION (auto-compute)
// ═══════════════════════════════════════════════════════════════════════════
header_line('5) OnlineTransaction — auto-compute observer works, direct write REJECTED');
DB::beginTransaction();
try {
    $employeeId = ensureEmployee('guard-online@t.local');
    $userId = ensureUser('guard-online@t.local');
    $accountId = ensureAccount('ONLINE');
    $serviceTypeId = ensureOnlineServiceType();
    $providerId = ensureOnlineServiceProvider();

    $tx = \App\Models\Online\OnlineTransaction::runProfitMutation(function () use ($employeeId, $userId, $accountId, $serviceTypeId, $providerId) {
        return \App\Models\Online\OnlineTransaction::create([
            'service_type_id' => $serviceTypeId, 'provider_id' => $providerId,
            'customer_name' => 'GUARD-TEST', 'customer_phone' => '0000', 'customer_country' => 'EG',
            'employee_id' => $employeeId, 'purchase_price' => 50, 'selling_price' => 100,
            'amount_paid' => 0, 'payment_method' => 'cash', 'account_id' => $accountId,
            'status' => 'pending', 'created_by' => $userId,
        ]);
    });

    if ((float) $tx->profit === 50.0) {
        pass('OnlineTransaction: auto-compute observer (saving in ::runProfitMutation()) produced profit=50');
    } else { fail('OnlineTransaction: auto-compute produced wrong value', 'got=' . $tx->profit); }

    $blocked = false; $blockedMsg = '';
    try { $tx->profit = 999; $tx->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('OnlineTransaction: direct write throws the guard');
    } else { fail('OnlineTransaction: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('OnlineTransaction: unexpected exception', $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 6) FAWRY TRANSACTION (auto-compute on create, service update path)
// ═══════════════════════════════════════════════════════════════════════════
header_line('6) FawryTransaction — auto-compute on create works, direct write REJECTED');
DB::beginTransaction();
try {
    // FawryTransaction.employee_id FK → users (not employees).
    $userId = ensureUser('guard-fawry@t.local');
    $accountId = ensureAccount('FAWRY');

    $tx = \App\Models\Fawry\FawryTransaction::runProfitMutation(function () use ($userId, $accountId) {
        return \App\Models\Fawry\FawryTransaction::create([
            'client_id' => null, 'client_name' => 'GUARD-TEST', 'operation_type' => 'deposit',
            'client_amount' => 100, 'fawry_price' => 80, 'selling_price' => 120,
            'employee_id' => $userId, 'account_id' => $accountId,
            'payment_method' => 'cash', 'amount' => 120,
            'reference_number' => 'GRD-' . substr(md5(uniqid()), 0, 8),
            'created_by_user_id' => $userId,
        ]);
    });

    if ((float) $tx->profit === 40.0) {
        pass('FawryTransaction: auto-compute (creating in ::runProfitMutation()) produced profit=40');
    } else { fail('FawryTransaction: auto-compute produced wrong value', 'got=' . $tx->profit); }

    $blocked = false; $blockedMsg = '';
    try { $tx->profit = 999; $tx->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('FawryTransaction: direct write throws the guard');
    } else { fail('FawryTransaction: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    \App\Models\Fawry\FawryTransaction::runProfitMutation(function () use ($tx) {
        $tx->profit = 60; $tx->save();
    });
    $tx->refresh();
    if ((float) $tx->profit === 60.0) {
        pass('FawryTransaction: FawryTransaction::runProfitMutation(...) wrap allows canonical write (profit=60)');
    } else { fail('FawryTransaction: wrapped write did not persist', 'got=' . $tx->profit); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('FawryTransaction: unexpected exception', $e->getMessage()); }

// ═══════════════════════════════════════════════════════════════════════════
// 7) VISA BOOKING
// ═══════════════════════════════════════════════════════════════════════════
header_line('7) VisaBooking — direct write REJECTED, wrapped write ALLOWED');
DB::beginTransaction();
try {
    $customerId = ensureCustomer('VISA');
    // VisaBooking.employee_id FK → users (not employees).
    $userId = ensureUser('guard-visa@t.local');
    $accountId = ensureAccount('VISA');
    $visaDetailId = ensureVisaDetail();

    $bookingId = (int) DB::table('visa_bookings')->insertGetId([
        'customer_id' => $customerId, 'visa_detail_id' => $visaDetailId,
        'module' => 'visas', 'purchase_price' => 500, 'selling_price' => 800,
        'service_fee' => 100, 'profit' => 400, 'currency' => 'EGP',
        'status' => 'submitted', 'agent_name' => 'GUARD',
        'account_id' => $accountId, 'employee_id' => $userId, 'created_by' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $booking = \App\Models\VisaBooking::findOrFail($bookingId);

    $blocked = false; $blockedMsg = '';
    try { $booking->profit = 999; $booking->save(); }
    catch (\RuntimeException $e) { $blocked = true; $blockedMsg = $e->getMessage(); }
    if ($blocked && str_contains($blockedMsg, 'لا يمكن تعديل عمود profit')) {
        pass('VisaBooking: direct write throws the guard');
    } else { fail('VisaBooking: direct write was NOT blocked', 'msg: ' . $blockedMsg); }

    \App\Models\VisaBooking::runProfitMutation(function () use ($booking) {
        $booking->profit = 500; $booking->save();
    });
    $booking->refresh();
    if ((float) $booking->profit === 500.0) {
        pass('VisaBooking: VisaBooking::runProfitMutation(...) wrap allows canonical write (profit=500)');
    } else { fail('VisaBooking: wrapped write did not persist', 'got=' . $booking->profit); }

    DB::rollBack();
} catch (\Throwable $e) { DB::rollBack(); fail('VisaBooking: unexpected exception', $e->getMessage()); }

// ── summary ────────────────────────────────────────────────────────────────
header_line('SUMMARY');
echo "  Passed: {$pass}\n  Failed: {$fail}\n  Total : " . ($pass + $fail) . "\n";
exit($fail === 0 ? 0 : 1);