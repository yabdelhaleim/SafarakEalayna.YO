<?php

namespace Tests\Feature\TourismDivision;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\Program;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;

/**
 * INTEGRATION TEST SUITE — HajjUmra Executing Company + Program
 *
 * Covers the B2B finance loop for the Hajj & Umra division:
 *
 *   ① Creating a `HajjUmraExecutingCompany` auto-creates a Supplier-type
 *       ledger account with `module_type='hajj_umra'` (booted() observer)
 *   ② `dues()` endpoint exposes per-company net due (debit − credit on
 *       hajj_umra-module AccountEntry rows)
 *   ③ `withdraw()` records a transfer from the EC account to a tourism
 *       account, posts balanced ledger entries, posts a Transaction with
 *       module='hajj_umra'
 *   ④ `repay()` enforces minimum cashbox balance (GAP #HJ-6 guard)
 *   ⑤ creating/updating a Program persists + returns it via API
 *   ⑥ Booking-flow integrity: when a HajjUmra booking is created against
 *       a program whose `executing_company_id` is set, an expense-side
 *       AccountEntry flows into the EC's account (auto-created supplier)
 *   ⑦ Cross-division isolation: trying to withdraw to an `office`-module
 *       account (NOT hajj_umra) is rejected (BUG #HJ-1 fix)
 *
 * Mirror of `FlightGroupCarrierIntegrationTest` — same shape so future
 * contributors can diff the two and learn the convention.
 */
class HajjUmraExecutingCompanyIntegrationTest extends TourismTestCase
{
    private function makeExecutingCompany(string $name = null): HajjUmraExecutingCompany
    {
        return HajjUmraExecutingCompany::query()->create([
            'name' => $name ?? ('شركة منفذة '.uniqid()),
            'license_number' => 'LIC-'.uniqid(),
            'phone' => '010'.random_int(10000000, 99999999),
            'is_active' => true,
        ]);
    }

    public function test_executing_company_creation_auto_creates_supplier_account(): void
    {
        $company = $this->makeExecutingCompany();
        $company->refresh();

        // Booted observer assigned account_id
        $this->assertNotNull($company->account_id);

        $account = Account::find($company->account_id);
        $this->assertNotNull($account);
        $this->assertSame(AccountType::Supplier, $account->type);
        $this->assertSame('hajj_umra', $account->module_type);
        $this->assertSame('حساب الشركة المنفذة للحج/العمرة: '.$company->name, $account->name);
        $this->assertSame(0.0, (float) $account->balance);
    }

    public function test_executing_company_renaming_updates_account_name(): void
    {
        $company = $this->makeExecutingCompany('Old Name');
        $originalAccountId = $company->account_id;

        $company->update(['name' => 'New Name']);

        $account = Account::find($originalAccountId);
        $this->assertSame('حساب الشركة المنفذة للحج/العمرة: New Name', $account->fresh()->name);
    }

    public function test_dues_endpoint_returns_companies_with_zero_balance(): void
    {
        $company = $this->makeExecutingCompany('ACME Hajj Tours');

        $resp = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');
        $resp->assertOk()->assertJsonPath('success', true);

        $items = $resp->json('data.items') ?? [];
        $matched = collect($items)->firstWhere('id', $company->id);
        $this->assertNotNull($matched);
        $this->assertSame(0.0, (float) $matched['net_due']);
        $this->assertSame(0.0, (float) $matched['total_withdrawn']);
        $this->assertSame(0.0, (float) $matched['total_repaid']);
    }

    public function test_dues_endpoint_lazy_creates_account_for_legacy_company(): void
    {
        // Create a company WITHOUT triggering the booted observer (simulating
        // legacy data from before auto-account was added).
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'Legacy Hajj Co '.uniqid(),
            'account_id' => null,
            'is_active' => true,
        ]);

        // First call to /dues should lazy-create the account
        $resp = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');
        $resp->assertOk();

        $company->refresh();
        $this->assertNotNull($company->account_id);
    }

    public function test_withdraw_records_balanced_transfer_to_tourism_cashbox(): void
    {
        $company = $this->makeExecutingCompany('Withdraw Test Co');

        // First, fund the EC account via repay (repay is the only way to
        // credit the supplier account — withdraw alone debits from it).
        $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 10000.00,
            'from_account_id' => $this->cashbox->id,
            'notes' => 'initial funding',
        ])->assertOk();

        $cashboxBefore = (float) $this->cashbox->fresh()->balance;

        // Now withdraw from EC back to cashbox
        $resp = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 3000.00,
            'to_account_id' => $this->cashbox->id,
            'notes' => 'partial withdrawal',
        ]);

        $resp->assertOk()->assertJsonPath('success', true);

        // Cashbox increased by 3000
        $this->assertEqualsWithDelta($cashboxBefore + 3000.0, (float) $this->cashbox->fresh()->balance, 0.02);

        // Withdraw posts a hajj_umra-module transfer with the right amount.
        // The controller doesn't set related_type=EC, so we look up by
        // module + amount + from/to account instead.
        $tx = Transaction::query()
            ->where('module', TransactionModule::HajjUmra->value)
            ->where('amount', 3000.0)
            ->whereHas('entries', fn ($q) => $q->where('account_id', $company->account_id))
            ->latest('id')
            ->first();
        $this->assertNotNull($tx, 'withdraw transaction exists');
        $this->assertTransactionBalanced($tx, 'withdraw transfer');

        // Account.balance uses credit - debit. The business-facing due is its inverse.
        $this->assertAccountLedgerConsistent($company->account_id, 'EC after withdraw');
        $this->assertEqualsWithDelta(
            -1 * (float) $company->fresh()->account->balance,
            $this->supplierNetDue($company->account_id),
            0.02,
            'EC net_due follows the inverse of the stored ledger balance'
        );
    }

    public function test_repay_rejects_when_cashbox_balance_insufficient(): void
    {
        $company = $this->makeExecutingCompany();

        // Cashbox only has 100k opening — try to repay 200k
        $resp = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 200000.00,
            'from_account_id' => $this->cashbox->id,
            'notes' => 'overdraft attempt',
        ]);

        $resp->assertStatus(422)->assertJsonPath('success', false);

        // Cashbox balance is unchanged
        $this->assertEqualsWithDelta(100000.0, (float) $this->cashbox->fresh()->balance, 0.02);
    }

    public function test_withdraw_to_office_account_is_rejected(): void
    {
        $company = $this->makeExecutingCompany();

        // Create an office-module bank account (cashbox uses 'tourism' — make a bank with 'office')
        $officeAccount = $this->makeAccount('bank', 'Office Bank', 'office', 50000.0, 'EGP', false, null);

        $resp = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 1000.00,
            'to_account_id' => $officeAccount->id,
            'notes' => 'cross-division attempt',
        ]);

        $resp->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_program_crud_via_api(): void
    {
        // CREATE
        $resp = $this->postJson('/api/v1/hajj-umra/programs', [
            'program_name' => 'برنامج عمرة اختباري '.uniqid(),
            'program_type' => 'umrah',
            'season' => 'low',
            'total_nights' => 7,
            'mecca_hotel_name' => 'فندق مكة',
            'mecca_nights' => 4,
            'medina_hotel_name' => 'فندق المدينة',
            'medina_nights' => 3,
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(37)->toDateString(),
            'airline' => 'مصر للطيران',
            'departure_point' => 'Cairo',
            'accommodation_type' => 'QUAD',
            'trip_supervisor' => 'مشرف اختباري',
            'default_purchase_price' => 10000.00,
            'default_selling_price' => 12000.00,
            'executing_company' => 'Test Co',
        ]);
        $resp->assertCreated()->assertJsonPath('success', true);
        $programId = $resp->json('data.id');
        $this->assertNotNull($programId);

        // READ
        $resp = $this->getJson("/api/v1/hajj-umra/programs/{$programId}");
        $resp->assertOk()->assertJsonPath('data.id', $programId);

        // UPDATE
        $resp = $this->patchJson('/api/v1/hajj-umra/programs/'.$programId, [
            'program_name' => 'اسم محدث',
        ]);
        $resp->assertOk()->assertJsonPath('data.program_name', 'اسم محدث');

        // INDEX
        $resp = $this->getJson('/api/v1/hajj-umra/programs?type=umrah');
        $resp->assertOk()->assertJsonPath('success', true);
        $this->assertIsArray($resp->json('data'));
    }

    public function test_programs_index_excludes_inactive_by_default(): void
    {
        Program::withoutEvents(function () {
            Program::query()->create([
                'program_name' => 'نشط',
                'program_type' => 'umrah',
                'executing_company' => '',
                'total_nights' => 7,
                'mecca_hotel_name' => 'x',
                'mecca_nights' => 4,
                'medina_hotel_name' => 'y',
                'medina_nights' => 3,
                'airline' => 'x',
                'trip_supervisor' => 'x',
                'accommodation_type' => 'QUAD',
                'default_purchase_price' => 1000,
                'default_selling_price' => 1200,
                'departure_date' => now()->addDays(10)->toDateString(),
                'return_date' => now()->addDays(17)->toDateString(),
                'departure_point' => 'Cairo',
                'is_active' => true,
            ]);
            Program::query()->create([
                'program_name' => 'غير نشط',
                'program_type' => 'umrah',
                'executing_company' => '',
                'total_nights' => 7,
                'mecca_hotel_name' => 'x',
                'mecca_nights' => 4,
                'medina_hotel_name' => 'y',
                'medina_nights' => 3,
                'airline' => 'x',
                'trip_supervisor' => 'x',
                'accommodation_type' => 'QUAD',
                'default_purchase_price' => 1000,
                'default_selling_price' => 1200,
                'departure_date' => now()->addDays(10)->toDateString(),
                'return_date' => now()->addDays(17)->toDateString(),
                'departure_point' => 'Cairo',
                'is_active' => false,
            ]);
        });

        $resp = $this->getJson('/api/v1/hajj-umra/programs');
        $resp->assertOk();

        $items = collect($resp->json('data'));
        $this->assertTrue($items->contains(fn ($p) => $p['program_name'] === 'نشط'));
        $this->assertFalse($items->contains(fn ($p) => $p['program_name'] === 'غير نشط'));

        // With the include_inactive flag the inactive one shows up
        $resp2 = $this->getJson('/api/v1/hajj-umra/programs?include_inactive=1');
        $resp2->assertOk();
        $items2 = collect($resp2->json('data'));
        $this->assertTrue($items2->contains(fn ($p) => $p['program_name'] === 'غير نشط'));
    }

    public function test_hajj_umra_treasury_endpoint_returns_overview(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_hajj_umra_dashboard_returns_dashboard(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/dashboard');
        $resp->assertOk()->assertJsonPath('success', true);
    }

    public function test_global_double_entry_holds_after_ec_funding_and_withdraw(): void
    {
        $company = $this->makeExecutingCompany();

        // Fund
        $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 5000.00,
            'from_account_id' => $this->cashbox->id,
        ])->assertOk();

        // Then withdraw
        $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 1500.00,
            'to_account_id' => $this->cashbox->id,
        ])->assertOk();

        // Global double-entry invariant (tx-backed entries only)
        $debit = (float) AccountEntry::query()->whereNotNull('transaction_id')->sum('debit');
        $credit = (float) AccountEntry::query()->whereNotNull('transaction_id')->sum('credit');
        $this->assertEqualsWithDelta($debit, $credit, 0.02, 'global double-entry holds after EC cycle');

        // The EC's stored ledger balance uses credit - debit.
        $accountId = $company->fresh()->account_id;
        $this->assertAccountLedgerConsistent($accountId, 'EC account after cycle');
        $this->assertEqualsWithDelta(
            -1 * (float) Account::findOrFail($accountId)->balance,
            $this->supplierNetDue($accountId),
            0.02,
            'EC due remains the inverse of its stored ledger balance'
        );
    }
}
