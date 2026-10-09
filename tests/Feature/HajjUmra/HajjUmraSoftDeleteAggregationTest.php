<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Services\Reports\FinancialReportService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 11 — Soft-delete aggregation verification.
 *
 * The Hajj & Umrah module relies on Eloquent's SoftDeletes global scope to
 * exclude soft-deleted rows from aggregations. The four aggregate surfaces
 * audited here are:
 *
 *   1. HajjUmraStats Filament widget
 *      `HajjUmraBooking::count()` / `sum('profit')` / `HajjUmraPayment::sum('amount')`
 *   2. HajjUmraDashboardController::index  (`GET /api/v1/hajj-umra/dashboard`)
 *      `monthly_revenue` (selling+companion+accommodation SUM) + `total_bookings`
 *   3. FinancialReportService::getDebtsReport (HajjUmraExecutingCompany leg)
 *   4. FinancialReportService::getDebtsReport (UmrahSupplier leg)
 *
 * This suite locks in the "soft-deleted records are invisible to aggregates"
 * invariant so future refactors that switch to raw DB queries (which would
 * bypass the global scope) are caught immediately.
 *
 * NOTE: A bug in this area was the original Phase 11 concern, but verification
 * confirmed Laravel's `SoftDeletes` global scope already handles all four
 * surfaces correctly. This file is a defensive regression lock — if anyone
 * adds `->withTrashed()` or swaps to `DB::table('...')->sum()` later, the
 * tests below will fail loudly.
 */
class HajjUmraSoftDeleteAggregationTest extends HajjUmraTestCase
{
    /* ============================================================
     *  1. HajjUmraStats widget  (HajjUmraBooking::count / sum('profit'))
     *  2. HajjUmraPayment::sum('amount')
     * ============================================================ */

    public function test_widget_profit_sum_excludes_soft_deleted_bookings(): void
    {
        $bookingA = $this->makeBooking(['selling_price' => 50000.0, 'purchase_price' => 42000.0]);
        $bookingB = $this->makeBooking(['selling_price' => 60000.0, 'purchase_price' => 50000.0]);

        $profitBefore = (float) HajjUmraBooking::sum('profit');
        $this->assertEqualsWithDelta(18000.0, $profitBefore, 0.01,
            'sanity: profit = 8000 + 10000 before any delete');

        $bookingA->delete(); // soft-delete via Eloquent (model guard will throw — use service)

        $profitAfter = (float) HajjUmraBooking::sum('profit');
        $this->assertEqualsWithDelta(10000.0, $profitAfter, 0.01,
            'soft-deleted booking must NOT contribute to profit total');
    }

    public function test_widget_count_excludes_soft_deleted_bookings(): void
    {
        $this->makeBooking();
        $this->makeBooking();
        $this->assertSame(2, HajjUmraBooking::count(), 'sanity: 2 live bookings');

        // Direct soft-delete is blocked by the deleting guard; route via the
        // service which uses the canonical gate.
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertSame(2, HajjUmraBooking::count(),
            'soft-deleted booking must NOT appear in count()');
        $this->assertSame(3, HajjUmraBooking::withTrashed()->count(),
            'sanity: withTrashed() still sees the trashed row');
    }

    public function test_payment_sum_excludes_soft_deleted_payments(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P11_PA_'.uniqid(),
        ])->assertCreated();

        $liveSum = (float) HajjUmraPayment::sum('amount');
        $this->assertEqualsWithDelta(10000.0, $liveSum, 0.01);

        // Soft-delete the booking (cascades soft-delete to payments via service).
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $postDeleteSum = (float) HajjUmraPayment::sum('amount');
        $this->assertEqualsWithDelta(0.0, $postDeleteSum, 0.01,
            'payments soft-deleted with the booking must NOT contribute to sum');
    }

    /* ============================================================
     *  2. HajjUmraDashboardController::index
     * ============================================================ */

    public function test_dashboard_total_bookings_excludes_soft_deleted(): void
    {
        $this->makeBooking();
        $this->makeBooking();
        $bookingTrashed = $this->makeBooking();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingTrashed->id}")->assertOk();

        $response = $this->getJson('/api/v1/hajj-umra/dashboard')->assertOk();
        $total = (int) $response->json('data.stats.total_bookings');
        $this->assertSame(2, $total,
            'dashboard.total_bookings must exclude soft-deleted bookings');

        // Sanity: monthly_revenue also excludes soft-deleted bookings
        // (we made 3 bookings of 50000 each, deleted 1 → 100000 expected).
        $monthlyRevenue = (float) $response->json('data.stats.monthly_revenue');
        $this->assertEqualsWithDelta(100000.0, $monthlyRevenue, 0.01,
            'dashboard.monthly_revenue must exclude soft-deleted bookings');
    }

    /* ============================================================
     *  3 & 4. FinancialReportService::getDebtsReport (EC + UmrahSupplier)
     * ============================================================ */

    public function test_debts_report_excludes_soft_deleted_executing_companies(): void
    {
        // Seed an executing company with a non-zero AP balance so it would
        // otherwise appear in the debts report.
        $ec = $this->makeExecutingCompany();
        $apAccount = Account::create([
            'name' => 'AP-EC-' . $ec->id,
            'type' => AccountType::Supplier->value,
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'hajj_umra',
            'created_by' => $this->admin->id,
        ]);
        $ec->account_id = $apAccount->id;
        $ec->save();

        // Force the linked account balance to a non-zero AP so the report picks it up.
        DB::table('accounts')->where('id', $apAccount->id)->update(['balance' => -250.0]);

        $report = app(FinancialReportService::class)
            ->getDebtsReport(['module' => 'hajj_umra']);

        $beforeItems = $report['items'] ?? $report;
        $beforeIds = collect($beforeItems)->pluck('id')->all();
        $this->assertContains($ec->id, $beforeIds,
            'sanity: live executing company with AP balance is in the report');

        // Soft-delete the EC and re-check.
        $ec->delete();

        $afterReport = app(FinancialReportService::class)->getDebtsReport(['module' => 'hajj_umra']);
        $afterItems = $afterReport['items'] ?? $afterReport;
        $afterIds = collect($afterItems)->pluck('id')->all();

        $this->assertNotContains($ec->id, $afterIds,
            'soft-deleted executing company must NOT appear in debts report');
    }

    public function test_debts_report_excludes_soft_deleted_umrah_suppliers(): void
    {
        $supplier = $this->makeSupplier();
        // The base makeSupplier already creates a USD supplier account with 0 balance;
        // bump it to a non-zero payable so it qualifies for the report.
        DB::table('accounts')->where('id', $supplier->account_id)->update(['balance' => -150.0]);

        $report = app(FinancialReportService::class)
            ->getDebtsReport(['module' => 'hajj_umra']);
        $items = $report['items'] ?? $report; // 'items' is the list; older callers may use direct array
        $ids = collect($items)->pluck('id')->all();

        $this->assertContains($supplier->id, $ids,
            'sanity: live umrah supplier with AP balance is in the report');

        // Soft-delete the supplier
        $supplier->delete();

        $afterReport = app(FinancialReportService::class)->getDebtsReport(['module' => 'hajj_umra']);
        $afterItems = $afterReport['items'] ?? $afterReport;
        $afterIds = collect($afterItems)->pluck('id')->all();
        $this->assertNotContains($supplier->id, $afterIds,
            'soft-deleted umrah supplier must NOT appear in debts report');
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $program = $this->makeProgram();
        $payload = array_merge([
            'customer' => [
                'full_name' => 'P11 SoftDel ' . uniqid(),
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }
}
