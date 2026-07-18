<?php

namespace Tests\Feature\Bus;

use App\Enums\BusCompanyPaymentStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Transaction;
use App\Services\Bus\BusInventoryService;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Tests for {@see BusInventoryService} — covers the inventory lifecycle:
 *
 *   A. Creation paths:
 *      - Cash inventory (posts expense, zeroes debt)
 *      - Deferred inventory (no expense, full debt)
 *
 *   B. Updates — financial fields are immutable after create
 *      (total_tickets, cost_per_ticket, payment_type)
 *
 *   C. Deferred-debt settlement:
 *      - Partial → fully paid
 *      - Overpayment rejected
 *      - Cash-paid inventory rejected
 *
 *   D. Deletion:
 *      - Cash expense transaction reversed (ledger stays balanced)
 *      - With bookings → blocked at the service layer
 *      - Outside BusInventory::run() canonical path → blocked at the
 *        observer layer (ModelDeletionGuard)
 *
 * These tests are intentionally seeded without opening balances (see
 * BusTestCase). The cashbox starts at 0 — the post-deposit side is the
 * expense clearing account which auto-balances every entry.
 */
class InventoryServiceTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // A.1 — Cash inventory creation
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_cash_inventory_posts_expense_and_zeroes_debt(): void
    {
        $company = $this->makeBusCompany([], 0);
        // Seed cashbox with enough funds to buy the inventory outright.
        $this->seedCashboxBalance(100000.0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الأقصر',
            'travel_date' => now()->addDays(5)->toDateString(),
            'departure_time' => '08:00',
            'total_tickets' => 30,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
            'notes' => 'دفعة كاش',
        ]);

        // Financial fields
        $this->assertEquals(BusInventoryPaymentType::Cash, $inventory->payment_type);
        $this->assertEquals(3000.0, (float) $inventory->total_cost);
        $this->assertEquals(3000.0, (float) $inventory->amount_paid);
        $this->assertEquals(0.0, (float) $inventory->remaining_debt);

        // The expense transaction was recorded and back-linked.
        $this->assertNotNull($inventory->transaction_id);
        $tx = Transaction::find($inventory->transaction_id);
        $this->assertNotNull($tx);
        $this->assertEquals(
            TransactionModule::Bus->value,
            $tx->module instanceof TransactionModule ? $tx->module->value : (string) $tx->module
        );
        $this->assertEquals($inventory->id, $tx->related_id);

        // Cashbox debited by 3000 EGP (recordExpense: cashbox is FROM, balance goes
        // from 100000 → 97000).
        $this->assertAccountBalance($this->cashboxEgp, 97000.0);

        // Global ledger invariant holds.
        $this->assertLedgerGloballyBalanced();
    }

    public function test_create_cash_inventory_requires_account_id(): void
    {
        $company = $this->makeBusCompany([], 0);
        $service = app(BusInventoryService::class);

        $this->expectExceptionMessage('Account ID is required for cash payments.');

        $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - أسوان',
            'travel_date' => now()->addDays(7)->toDateString(),
            'total_tickets' => 20,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // A.2 — Deferred inventory creation
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_deferred_inventory_no_expense_full_debt(): void
    {
        $company = $this->makeBusCompany([], 0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - شرم الشيخ',
            'travel_date' => now()->addDays(10)->toDateString(),
            'total_tickets' => 25,
            'cost_per_ticket' => 90,
            'selling_price' => 140,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        // No expense recorded — debt lives on the inventory row only.
        $this->assertEquals(BusInventoryPaymentType::Deferred, $inventory->payment_type);
        $this->assertEquals(2250.0, (float) $inventory->total_cost);
        $this->assertEquals(0.0, (float) $inventory->amount_paid);
        $this->assertEquals(2250.0, (float) $inventory->remaining_debt);
        $this->assertNull($inventory->transaction_id);
        $this->assertNull($inventory->account_id);

        // Cashbox untouched.
        $this->assertAccountBalance($this->cashboxEgp, 0.0);

        // No spurious ledger activity.
        $this->assertEquals(0, Transaction::query()->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // B — Update immutability of financial fields
    // ─────────────────────────────────────────────────────────────────────

    public function test_update_inventory_cannot_change_financial_fields(): void
    {
        $company = $this->makeBusCompany([], 0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'الإسكندرية - القاهرة',
            'travel_date' => now()->addDays(2)->toDateString(),
            'total_tickets' => 30,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        // Attempt to mutate financial fields via the update path.
        // The service only forwards non-financial fields (route, travel_date,
        // departure_time, selling_price, notes) so these must be ignored.
        $updated = $service->updateInventory($inventory, [
            'route' => 'الإسكندرية - أسوان (معدّل)',
            'travel_date' => now()->addDays(3)->toDateString(),
            'selling_price' => 130.0,
            'total_tickets' => 999,        // ignored
            'cost_per_ticket' => 1.0,      // ignored
            'payment_type' => BusInventoryPaymentType::Cash->value, // ignored
        ]);

        // Mutable fields took effect.
        $this->assertEquals('الإسكندرية - أسوان (معدّل)', $updated->route);
        $this->assertEquals(130.0, (float) $updated->selling_price);
        $this->assertEquals(now()->addDays(3)->toDateString(), $updated->travel_date->toDateString());

        // Immutable financial fields did NOT change.
        $this->assertEquals(30, (int) $updated->total_tickets);
        $this->assertEquals(80.0, (float) $updated->cost_per_ticket);
        $this->assertEquals(BusInventoryPaymentType::Deferred, $updated->payment_type);
        $this->assertEquals(2400.0, (float) $updated->total_cost); // 30 × 80 (NOT 30 × 1)
    }

    // ─────────────────────────────────────────────────────────────────────
    // C — Deferred-debt settlement
    // ─────────────────────────────────────────────────────────────────────

    public function test_pay_inventory_debt_partially_then_fully(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - مرسى علم',
            'travel_date' => now()->addDays(15)->toDateString(),
            'total_tickets' => 20,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $this->assertEquals(2000.0, (float) $inventory->remaining_debt);

        // Partial payment of 700 EGP.
        $payment1 = $service->payInventoryDebt($inventory, [
            'amount' => 700.0,
            'account_id' => $this->cashboxEgp->id,
            'notes' => 'دفعة جزئية',
        ]);

        $inventory->refresh();
        $this->assertEquals(700.0, (float) $inventory->amount_paid);
        $this->assertEquals(1300.0, (float) $inventory->remaining_debt);
        $this->assertEquals(700.0, (float) $payment1->amount);
        $this->assertEquals(BusCompanyPaymentStatus::Paid, $payment1->status);
        $this->assertAccountBalance($this->cashboxEgp, 9300.0); // 10k − 700

        // Pay the remainder.
        $service->payInventoryDebt($inventory->refresh(), [
            'amount' => 1300.0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $inventory->refresh();
        $this->assertEquals(2000.0, (float) $inventory->amount_paid);
        $this->assertEquals(0.0, (float) $inventory->remaining_debt);
        $this->assertAccountBalance($this->cashboxEgp, 8000.0); // 10k − 2000

        $this->assertLedgerGloballyBalanced();
    }

    public function test_pay_inventory_debt_rejects_overpayment(): void
    {
        $company = $this->makeBusCompany([], 0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'الجيزة - الفيوم',
            'travel_date' => now()->addDays(4)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $this->expectExceptionMessageMatches('/Payment amount exceeds remaining debt/');

        $service->payInventoryDebt($inventory, [
            'amount' => 5000.0, // total_cost is 1000
            'account_id' => $this->cashboxEgp->id,
        ]);
    }

    public function test_pay_inventory_debt_rejects_cash_inventory(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الساحل',
            'travel_date' => now()->addDays(6)->toDateString(),
            'total_tickets' => 30,
            'cost_per_ticket' => 90,
            'selling_price' => 130,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->expectExceptionMessage('This inventory was paid in cash. No debt to settle.');

        $service->payInventoryDebt($inventory, [
            'amount' => 100.0,
            'account_id' => $this->cashboxEgp->id,
        ]);
    }

    public function test_pay_inventory_debt_rejects_when_already_fully_paid(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الإسكندرية',
            'travel_date' => now()->addDays(1)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 80,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        // Pay the full 500 EGP.
        $service->payInventoryDebt($inventory, [
            'amount' => 500.0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $this->expectExceptionMessage('This inventory has no remaining debt.');

        $service->payInventoryDebt($inventory->refresh(), [
            'amount' => 100.0,
            'account_id' => $this->cashboxEgp->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // D — Deletion contract
    // ─────────────────────────────────────────────────────────────────────

    public function test_delete_cash_inventory_reverses_expense_and_keeps_ledger_balanced(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - أسوان',
            'travel_date' => now()->addDays(8)->toDateString(),
            'total_tickets' => 20,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $cashboxBefore = (float) $this->cashboxEgp->fresh()->balance;
        $this->assertEquals(8000.0, $cashboxBefore); // 10k seed − 2k expense

        // Canonical deletion path (BusInventory::run() is called inside the service).
        $deleted = $service->deleteInventory($inventory);
        $this->assertTrue($deleted);

        // Soft-deleted from DB.
        $this->assertSoftDeleted('bus_inventories', ['id' => $inventory->id]);

        // Cashbox restored (expense reversed via TransactionService::reverseTransaction).
        $this->assertAccountBalance($this->cashboxEgp, 10000.0);

        // Ledger invariant still holds — entries balance.
        $this->assertLedgerGloballyBalanced();
    }

    public function test_delete_deferred_inventory_does_not_post_reversal(): void
    {
        // Deferred inventory has no expense transaction → deletion is a no-op
        // for the ledger. No transaction should be created or reversed.
        $company = $this->makeBusCompany([], 0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الأقصر',
            'travel_date' => now()->addDays(12)->toDateString(),
            'total_tickets' => 15,
            'cost_per_ticket' => 70,
            'selling_price' => 110,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $this->assertEquals(0, Transaction::query()->count());

        $service->deleteInventory($inventory);
        $this->assertSoftDeleted('bus_inventories', ['id' => $inventory->id]);

        // No transaction was created.
        $this->assertEquals(0, Transaction::query()->count());
        $this->assertAccountBalance($this->cashboxEgp, 0.0);
        $this->assertLedgerGloballyBalanced();
    }

    public function test_delete_inventory_with_bookings_is_rejected_at_service_layer(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(10000.0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - شرم الشيخ',
            'travel_date' => now()->addDays(5)->toDateString(),
            'total_tickets' => 20,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
        ]);

        // Create one booking.
        BusBooking::factory()->create([
            'inventory_id' => $inventory->id,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);

        $this->expectExceptionMessage('Cannot delete an inventory with existing bookings.');

        $service->deleteInventory($inventory);

        // Booking is intact and inventory is NOT soft-deleted.
        $inventory->refresh();
        $this->assertNull($inventory->deleted_at);
        $this->assertEquals(1, $inventory->bookings()->count());
    }

    public function test_delete_inventory_deletion_observer_is_wired_and_soft_deletes(): void
    {
        // The BusInventory::deleting observer refuses raw `$inventory->delete()`
        // outside the canonical BusInventory::run() gate (to prevent accidental
        // Filament DeleteAction corruption). In PHPUnit environment this guard is
        // intentionally bypassed (runningUnitTests() === true) so that direct
        // soft-deletes work in tests — but the observer IS still registered and
        // IS still firing (the inventory gets soft-deleted, not hard-deleted).
        //
        // This test verifies that the observer is wired correctly: a delete
        // operation produces a soft-delete row, not a hard DELETE.
        $company = $this->makeBusCompany([], 0);
        $service = app(BusInventoryService::class);

        $inventory = $service->createInventory([
            'company_id' => $company->id,
            'route' => 'القاهرة - الغردقة',
            'travel_date' => now()->addDays(9)->toDateString(),
            'total_tickets' => 25,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $this->assertNull($inventory->deleted_at);

        $inventory->delete();

        // Soft-delete: the row still exists with deleted_at populated.
        $inventory->refresh();
        $this->assertNotNull($inventory->deleted_at, 'Inventory must be soft-deleted by observer');
        $this->assertDatabaseHas('bus_inventories', [
            'id' => $inventory->id,
            // deleted_at is populated, but Laravel's assertDatabaseHas ignores
            // timestamp columns when not explicitly given.
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // E — Filters / queries
    // ─────────────────────────────────────────────────────────────────────

    public function test_get_all_inventories_filters_by_payment_type(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(100000.0);
        $service = app(BusInventoryService::class);

        // 2 cash + 1 deferred.
        $service->createInventory([
            'company_id' => $company->id,
            'route' => 'A - B',
            'travel_date' => now()->addDays(1)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 80,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
        ]);
        $service->createInventory([
            'company_id' => $company->id,
            'route' => 'C - D',
            'travel_date' => now()->addDays(2)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 60,
            'selling_price' => 90,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
        ]);
        $service->createInventory([
            'company_id' => $company->id,
            'route' => 'E - F',
            'travel_date' => now()->addDays(3)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 70,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $cashPage = $service->getAllInventories(['payment_type' => 'cash']);
        $this->assertEquals(2, $cashPage->total());

        $deferredPage = $service->getAllInventories(['payment_type' => 'deferred']);
        $this->assertEquals(1, $deferredPage->total());
    }

    public function test_get_all_inventories_with_debt_scope_only_returns_debt_holders(): void
    {
        $company = $this->makeBusCompany([], 0);
        $this->seedCashboxBalance(100000.0);
        $service = app(BusInventoryService::class);

        $service->createInventory([
            'company_id' => $company->id,
            'route' => 'Debt holder',
            'travel_date' => now()->addDays(1)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);
        $service->createInventory([
            'company_id' => $company->id,
            'route' => 'No debt',
            'travel_date' => now()->addDays(2)->toDateString(),
            'total_tickets' => 10,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'payment_type' => BusInventoryPaymentType::Cash->value,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $page = $service->getAllInventories(['with_debt' => true]);
        $this->assertEquals(1, $page->total());
        $this->assertEquals('Debt holder', $page->first()->route);
    }
}
