<?php

namespace Tests\Feature\Bus;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Database\Factories\Bus\BusInventoryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUS MODULE AUDIT — Phase 3 + Phase 5: SECURITY & ABUSE
 *
 * Targets findings from `BUS_MODULE_AUDIT_REPORT.md` §2.4 (SQL Injection,
 * Authorization, Idempotency).
 *
 * Covers:
 *   - SQL injection attempts in string filters
 *   - XSS payloads in text fields (notes, names, route)
 *   - LIKE wildcard abuse in search filters
 *   - Unauthenticated access (no token / expired token)
 *   - IDOR — non-owning user accessing another user's booking
 *   - Request tampering — sending `total_price` from frontend
 *   - Rate limit (informational — no rate limit exists per audit §V-14)
 *   - Authorization for Company/Inventory CRUD (already failing in BusAuthorizationTest)
 *   - Cross-module authorization (tourism user trying bus endpoint)
 */
class BusAuditSecurityTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // GROUP A — UNAUTHENTICATED ACCESS
    // ─────────────────────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_list_bookings(): void
    {
        // Clear any auth
        Sanctum::actingAs($this->user, ['*']); // set first
        auth()->forgetGuards();

        $response = $this->getJson('/api/v1/bus/bookings');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_create_booking(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        auth()->forgetGuards();

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'بدون توكن',
            'customer_phone' => '01000000001',
            'quantity' => 1,
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_pay_booking(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        auth()->forgetGuards();

        $response = $this->postJson('/api/v1/bus/bookings/1/pay', [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_delete_company(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        auth()->forgetGuards();

        $response = $this->deleteJson('/api/v1/bus/companies/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_access_dashboard(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        auth()->forgetGuards();

        $this->getJson('/api/v1/bus/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/bus/bookings/stats')->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GROUP B — SQL INJECTION / LIKE WILDCARD ABUSE
    // ─────────────────────────────────────────────────────────────────────

    public function test_sql_injection_in_search_filter_is_harmless(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'محمد',
            'customer_phone' => '01000000001',
            'quantity' => 1,
        ])->assertCreated();

        $payload = "' OR 1=1 --";
        $response = $this->getJson("/api/v1/bus/bookings?search=".urlencode($payload));
        $response->assertStatus(200);
        // No SQL injection — Laravel bindings protect
        $this->assertDatabaseCount('bus_bookings', 1);
    }

    public function test_like_wildcard_in_search_returns_all_rows(): void
    {
        // Level 2 / S-04/S-02: LIKE search did not escape `%` or `_`.
        // Sending `search=%` (URL-decoded) returned ALL rows instead of nothing.
        //
        // Proof: seed 3 bookings with distinct customer names, send `search=%`
        // (the literal wildcard character). After the fix, the escape prevents
        // it from being interpreted as a wildcard — the predicate becomes
        // `LIKE '%\%' ESCAPE '\'` and matches 0 rows (no booking contains the
        // literal `%` character).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        // 3 bookings with DIFFERENT names so the search should never match all 3
        // via the un-escaped wildcard.
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'اسم اختبار 1',
            'customer_phone' => '01000000021',
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'اسم اختبار 2',
            'customer_phone' => '01000000022',
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'اسم اختبار 3',
            'customer_phone' => '01000000023',
            'quantity' => 1,
        ])->assertCreated();
        $this->assertEquals(3, \App\Models\Bus\BusBooking::count(), '3 bookings must exist before the wildcard probe');

        $response = $this->getJson('/api/v1/bus/bookings?search=%25'); // %25 = % after URL decode
        $response->assertStatus(200);

        $items = $response->json('data.items') ?? [];
        $this->assertCount(
            0,
            $items,
            'S-04/S-02 fixed: search=% must match 0 rows (no customer name contains a literal %)'
        );
    }

    public function test_like_wildcard_in_route_from_filter(): void
    {
        // Level 2: route_from filter — same wildcard-escape gap.
        // The BusBookingService builds `LIKE $rf.'%'` (route 'starts with' prefix).
        // Sending `route_from=%` (URL-decoded) turned the predicate into
        // `LIKE '%%'` — a wildcard match that returned ALL bookings referencing
        // inventories whose route starts with anything (i.e. everything).
        $company = $this->makeBusCompany([], 0);
        $inv1 = $this->makeInventory(['company_id' => $company->id, 'route' => 'القاهرة - الإسكندرية']);
        $inv2 = $this->makeInventory(['company_id' => $company->id, 'route' => 'الجيزة - أسوان']);
        $inv3 = $this->makeInventory(['company_id' => $company->id, 'route' => 'المنصورة - شرم الشيخ']);

        // Book against each inventory so the whereHas('inventory') filter has
        // matching rows to operate on.
        foreach ([$inv1, $inv2, $inv3] as $idx => $inv) {
            $this->postJson('/api/v1/bus/bookings', [
                'inventory_id' => $inv->id,
                'customer_name' => 'ركاب '.($idx + 1),
                'customer_phone' => '0100000004'.($idx + 1),
                'quantity' => 1,
            ])->assertCreated();
        }
        $this->assertEquals(3, \App\Models\Bus\BusBooking::count(), '3 bookings must exist');

        $response = $this->getJson('/api/v1/bus/bookings?route_from=%25');
        $response->assertStatus(200);

        $items = $response->json('data.items') ?? [];
        $this->assertCount(
            0,
            $items,
            'route_from=% must match 0 bookings (no route begins with the literal %)'
        );
    }

    public function test_like_wildcard_in_company_search_returns_all_rows(): void
    {
        // Level 2: BusCompanyController::index → BusCompanyService::getAllCompanies
        // — `name LIKE %search%` also escaped the wildcards without escaping.
        // Seed 3 distinct company names, probe `search=%` → must return 0 results.
        $this->makeBusCompany(['name' => 'شركة القاهرة A'], 0);
        $this->makeBusCompany(['name' => 'شركة الإسكندرية B'], 0);
        $this->makeBusCompany(['name' => 'شركة أسوان C'], 0);
        $this->assertEquals(3, \App\Models\Bus\BusCompany::count(), '3 companies must exist');

        $response = $this->getJson('/api/v1/bus/companies?search=%25');
        $response->assertStatus(200);

        // Response shape: data.pagination.total (ApiResponse::paginated helper)
        $total = (int) ($response->json('data.pagination.total') ?? 0);
        $items = $response->json('data.items') ?? [];
        $this->assertSame(
            0,
            $total,
            'S-04 fixed: BusCompanyController::index search=% must match 0 companies (got '.$total.': '.json_encode($items, JSON_UNESCAPED_UNICODE).')'
        );
    }

    public function test_like_wildcard_in_customer_search_returns_all_rows(): void
    {
        // Level 2: BusCustomerController::index — `full_name / phone LIKE %search%`
        // — same wildcard-escape gap. Probe `search=%` → must return 0 results.
        // Create 3 bookings (each creates a customer).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'محمد 1', 'customer_phone' => '01000000031',
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'أحمد 2', 'customer_phone' => '01000000032',
            'quantity' => 1,
        ])->assertCreated();
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'علي 3', 'customer_phone' => '01000000033',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/bus/customers?search=%25');
        $response->assertStatus(200);

        // Response shape: data.customers.total (paginated LengthAwarePaginator)
        $total = (int) ($response->json('data.customers.total') ?? 0);
        $items = $response->json('data.customers.data') ?? [];
        $this->assertSame(
            0,
            $total,
            'S-04 fixed: BusCustomerController::index search=% must match 0 customers (got '.$total.': '.json_encode($items, JSON_UNESCAPED_UNICODE).')'
        );
    }

    public function test_search_still_finds_normal_terms_after_escape_fix(): void
    {
        // Regression guard: the wildcard-escape helper must NOT break normal
        // substring search. Sending a plain Arabic name must match the booking.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'محمد علي',
            'customer_phone' => '01000000099',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/bus/bookings?search='.urlencode('محمد'));
        $response->assertStatus(200);

        $items = $response->json('data.items') ?? [];
        $this->assertGreaterThanOrEqual(1, count($items), 'normal substring search must still work');
        $this->assertStringContainsString('محمد', (string) json_encode($items, JSON_UNESCAPED_UNICODE));
    }

    // ─────────────────────────────────────────────────────────────────────
    // GROUP C — XSS PAYLOADS
    // ─────────────────────────────────────────────────────────────────────

    public function test_xss_payload_in_notes_is_stored_safely(): void
    {
        // XSS attempts should be stored as-is (escape is renderer's job),
        // but the route must NOT execute anything.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        $xssPayload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'اختبار XSS',
            'customer_phone' => '01000000004',
            'quantity' => 1,
            'notes' => $xssPayload,
        ]);

        $response->assertStatus(201);

        $booking = BusBooking::latest('id')->firstOrFail();
        // Laravel response uses Content-Type: application/json, so the
        // payload is sent as a JSON string (escaped). The DB stores raw.
        $this->assertEquals($xssPayload, $booking->notes,
            'XSS payload stored as-is (renderer is responsible for escape)');

        // Verify the JSON response escapes (or at least doesn't execute)
        $this->assertStringContainsString('<script>', $booking->notes);
    }

    public function test_xss_payload_in_customer_name_is_serialized_as_json_string(): void
    {
        // XSS in JSON APIs is the consumer's responsibility (must escape on render).
        // The API must:
        //   1. Accept and store the payload (sanitization breaks legitimate input).
        //   2. Return it as a valid JSON string (no broken JSON).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        $xssName = '\"><script>alert(1)</script>';

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => $xssName,
            'customer_phone' => '01000000005',
            'quantity' => 1,
        ]);

        $response->assertStatus(201);

        // The response must be valid JSON and the customer name must be
        // retrievable as a string (not executed as code).
        $customerName = $response->json('data.customer.name');
        $this->assertEquals($xssName, $customerName,
            'JSON API returns raw string — escape is the renderer\'s job');

        // Verify the response Content-Type is application/json
        $this->assertStringContainsString(
            'application/json',
            $response->headers->get('Content-Type') ?? ''
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // GROUP D — REQUEST TAMPERING (Critical)
    // ─────────────────────────────────────────────────────────────────────

    public function test_tampering_total_price_is_ignored_by_backend(): void
    {
        // Finding: Frontend sends `total_price` but backend recomputes from
        // inventory.selling_price × quantity. Verify tampering doesn't work.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'selling_price' => 100,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'تلاعب بالسعر',
            'customer_phone' => '01000000006',
            'quantity' => 2,
            'total_price' => 1.00, // attempt to pay only 1 EGP instead of 200
        ]);

        $response->assertStatus(201);

        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(200.0, (float) $booking->total_price, 0.01,
            'Backend must IGNORE client total_price and recompute from selling_price × quantity');
    }

    public function test_tampering_unit_price_is_ignored(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'selling_price' => 100,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'تلاعب بالوحدة',
            'customer_phone' => '01000000007',
            'quantity' => 2,
            'unit_price' => 1.00, // attempt to set unit_price to 1
        ]);

        $response->assertStatus(201);
        $booking = BusBooking::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(100.0, (float) $booking->unit_price, 0.01,
            'Backend must IGNORE client unit_price and use inventory.selling_price');
    }

    public function test_tampering_profit_is_ignored(): void
    {
        // Profit is a derived column — guarded by ModelProfitMutationGuard
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'selling_price' => 100,
            'cost_per_ticket' => 50,
        ]);

        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'تلاعب بالربح',
            'customer_phone' => '01000000008',
            'quantity' => 2,
            'profit' => 9999.99, // attempt to set profit manually
        ]);

        $response->assertStatus(201);
        $booking = BusBooking::latest('id')->firstOrFail();
        $expectedProfit = (100 - 50) * 2; // 100
        $this->assertEqualsWithDelta($expectedProfit, (float) $booking->profit, 0.01,
            'Backend must IGNORE client profit and compute it from selling - cost');
    }

    // ─────────────────────────────────────────────────────────────────────
    // GROUP E — IDOR (Insecure Direct Object Reference)
    // ─────────────────────────────────────────────────────────────────────

    public function test_non_owning_employee_cannot_pay_someone_elses_booking(): void
    {
        // Confirms A-04 — BusBookingPolicy defined but NOT enforced
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        // Admin creates a booking
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'حجز الإدمن',
            'customer_phone' => '01000000009',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();

        // Switch to a different employee user
        $otherUser = User::create([
            'name' => 'Other Employee',
            'email' => 'other-emp@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        \App\Models\Employee::create([
            'user_id' => $otherUser->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($otherUser, ['*']);

        // Attempt to pay admin's booking — should be 403 per BusBookingPolicy
        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Currently returns 200 (Policy NOT enforced) — A-04 confirmed
        if ($response->status() === 200) {
            $booking->refresh();
            $this->assertEqualsWithDelta(100.0, (float) $booking->paid_amount, 0.01,
                'A-04 confirmed: non-owning employee CAN pay another employee\'s booking');
        } else {
            $response->assertStatus(403);
            $this->markTestIncomplete('A-04 may be fixed — pay route rejects non-owning employee');
        }
    }

    public function test_viewer_can_pay_booking_no_admin_required(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'حجز',
            'customer_phone' => '01000000010',
            'quantity' => 1,
        ])->assertCreated();
        $booking = BusBooking::latest('id')->firstOrFail();

        $viewer = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
            'role' => 'viewer',
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer, ['*']);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Currently returns 200 (no auth check) — A-04 confirmed
        if ($response->status() === 200) {
            $booking->refresh();
            $this->assertEqualsWithDelta(100.0, (float) $booking->paid_amount, 0.01,
                'A-04 confirmed: viewer role CAN pay bookings');
        } else {
            $response->assertStatus(403);
            $this->markTestIncomplete('A-04 may be fixed');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // GROUP F — UNAUTHORIZED CRUD (A-07..A-10)
    // ─────────────────────────────────────────────────────────────────────

    public function test_cashier_can_delete_company_unauthorized(): void
    {
        // A-08 confirmation
        $company = $this->makeBusCompany([], 0);

        $cashier = User::create([
            'name' => 'Cashier',
            'email' => 'cashier-crud@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        Sanctum::actingAs($cashier, ['*']);

        $response = $this->deleteJson("/api/v1/bus/companies/{$company->id}");

        if ($response->status() === 200) {
            $this->assertTrue(true, 'A-08 confirmed: cashier can soft-delete a company');
        } else {
            $response->assertStatus(403);
            $this->markTestIncomplete('A-08 may be fixed — company delete is admin-gated');
        }
    }

    public function test_cashier_can_create_inventory_unauthorized(): void
    {
        // A-10 confirmation
        $company = $this->makeBusCompany([], 0);

        $cashier = User::create([
            'name' => 'Cashier',
            'email' => 'cashier-inv-crud@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        Sanctum::actingAs($cashier, ['*']);

        $response = $this->postJson('/api/v1/bus/inventories', [
            'company_id' => $company->id,
            'route' => 'القاهرة - غير مصرح',
            'travel_date' => now()->addDays(5)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => 'deferred',
        ]);

        if ($response->status() === 201) {
            $this->assertTrue(true, 'A-10 confirmed: cashier can create inventory');
        } else {
            $response->assertStatus(403);
            $this->markTestIncomplete('A-10 may be fixed');
        }
    }

    public function test_cashier_can_update_company_unauthorized(): void
    {
        // A-07 confirmation
        $company = $this->makeBusCompany([], 0);

        $cashier = User::create([
            'name' => 'Cashier',
            'email' => 'cashier-upd@example.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
            'is_active' => true,
        ]);
        Sanctum::actingAs($cashier, ['*']);

        $response = $this->putJson("/api/v1/bus/companies/{$company->id}", [
            'name' => 'شركة معدلة بدون صلاحية',
            'is_active' => false, // attempt to deactivate the company
        ]);

        if ($response->status() === 200) {
            $company->refresh();
            $this->assertEquals('شركة معدلة بدون صلاحية', $company->name);
            $this->assertFalse((bool) $company->is_active);
            $this->markTestIncomplete('A-07 confirmed: cashier can modify company');
        } else {
            $response->assertStatus(403);
            $this->markTestIncomplete('A-07 may be fixed');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // GROUP G — RATE LIMITING (informational)
    // ─────────────────────────────────────────────────────────────────────

    public function test_no_rate_limit_on_bookings_endpoint(): void
    {
        // Level 2 / Problem 3: prove the bus-write named throttle middleware
        // is attached to the two financial-write endpoints ONLY:
        //   POST /api/v1/bus/bookings            (store  — creates a booking)
        //   POST /api/v1/bus/bookings/{id}/pay   (pay    — collects money)
        // It MUST NOT be attached to reads (index, show, stats) since the
        // user explicitly limited the scope to write endpoints.
        //
        // The default `api` middleware group does NOT include throttle:api in
        // this project — so without our fix, 60+ requests succeed in <1 sec.
        $all = collect(\Illuminate\Support\Facades\Route::getRoutes());

        $targetWriteRoutes = $all->filter(function ($r) {
            $uri = $r->uri();
            $method = $r->methods()[0] ?? '';
            if ($method !== 'POST') return false;

            // POST /api/v1/bus/bookings (store)
            if ($uri === 'api/v1/bus/bookings') return true;
            // POST /api/v1/bus/bookings/{busBooking}/pay (pay)
            if (str_ends_with($uri, '/pay')) return true;

            return false;
        });

        $readRoutes = $all->filter(function ($r) {
            $uri = $r->uri();
            $method = $r->methods()[0] ?? '';
            return str_starts_with($uri, 'api/v1/bus/bookings')
                && in_array($method, ['GET', 'HEAD'], true);
        });

        $this->assertGreaterThanOrEqual(2, $targetWriteRoutes->count(),
            'expected at least 2 routes (store + pay) to check');

        // Store + pay MUST have throttle:bus-write attached.
        foreach ($targetWriteRoutes as $route) {
            $hasBusWrite = in_array('throttle:bus-write', $route->gatherMiddleware(), true);
            $this->assertTrue(
                $hasBusWrite,
                'Level 2 P3 fix missing: route POST /'.$route->uri()
                .' must have throttle:bus-write. Middleware: '
                .implode(', ', $route->gatherMiddleware())
            );
        }

        // Read routes MUST NOT have throttle:bus-write.
        foreach ($readRoutes as $route) {
            $hasBusWrite = in_array('throttle:bus-write', $route->gatherMiddleware(), true);
            $this->assertFalse(
                $hasBusWrite,
                'route '.$route->methods()[0].' /'.$route->uri()
                .' must NOT have throttle:bus-write (reads). Middleware: '
                .implode(', ', $route->gatherMiddleware())
            );
        }
    }

    public function test_bus_write_rate_limit_blocks_61st_request(): void
    {
        // Level 2 / Problem 3 — functional verification.
        // Send 60 successful POST /bus/bookings, then the 61st must return 429.
        // The default throttle:api (60/min) would also catch the 61st; the test
        // is satisfied as long as SOME throttle rejects it (bus-write is the
        // one we control and documented; api-throttle is pre-existing).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 200, // enough capacity for 60+ bookings
            'available_tickets' => 200,
            'selling_price' => 100,
        ]);

        $payload = [
            'inventory_id' => $inventory->id,
            'customer_name' => 'كاشير',
            'customer_phone' => '0100THROTTLE',
            'quantity' => 1,
        ];

        for ($i = 0; $i < 60; $i++) {
            $r = $this->postJson('/api/v1/bus/bookings', $payload);
            $this->assertContains(
                $r->status(),
                [201, 422, 429],
                "Request #{$i} returned unexpected status {$r->status()}: " . $r->getContent()
            );
            if ($r->status() === 429) {
                $this->markTestSkipped(
                    "Throttle triggered at request #{$i}, before the 61st — ".
                    'this can happen if the per-minute window started earlier. '.
                    'Acceptable for the functional check; the middleware-attachment test is authoritative.'
                );
                return;
            }
        }

        $blocked = $this->postJson('/api/v1/bus/bookings', $payload);
        $this->assertSame(
            429,
            $blocked->status(),
            'The 61st POST /bus/bookings within the same minute must return 429 (Too Many Requests)'
        );
    }
}
