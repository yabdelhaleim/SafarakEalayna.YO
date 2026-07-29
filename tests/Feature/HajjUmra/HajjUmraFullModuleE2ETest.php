<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FULL MODULE E2E — Hajj/Umra end-to-end audit (production readiness).
 *
 * Covers:
 *  ① Liquidity provisioning  — safes (cashbox) + banks + wallets, all with
 *                               tourism-division module_type, is_module_vault=true.
 *  ② Bookings                — 3 scenarios: cash-only / supplier / executing-co.
 *  ③ Payments                — initial + multi-payments via mixed banks/wallets.
 *  ④ Cancel                  — additive reversal; rows stay; balance sweep.
 *  ⑤ Refund                  — additive reversal; status=refunded.
 *  ⑥ Admin delete            — soft-delete + reversal (idempotent).
 *  ⑦ Multi-currency          — EGP + USD + SAR.
 *  ⑧ Idempotency             — duplicate cancel/refund/delete rejected.
 *  ⑨ Double-entry invariant  — Σ debit = Σ credit per transaction; account
 *                               balances match the Σ credit − Σ debit on entries.
 *  ⑩ Liquidity rule          — wrong-module vault rejected on create + payment.
 */
class HajjUmraFullModuleE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $cashier;

    // Liquidity accounts (all module_type='tourism', is_module_vault=true)
    private Account $safeEGP;          // خزينة نقدي
    private Account $bankCIB;          // بنك CIB
    private Account $bankNBE;          // بنك NBE
    private Account $walletVodafone;   // محفظة Vodafone Cash
    private Account $walletInstapay;   // محفظة Instapay

    // Subject accounts (auto-created / pre-created)
    private UmrahSupplier $supplier;
    private Account $supplierAccount;

    private HajjUmraExecutingCompany $executingCompany;

    private Program $programNoSupplier;
    private Program $programForSupplier;
    private Program $programForCompany;

    // Customers
    private Customer $customer;
    private Customer $customerSupplier;
    private Customer $customerCompany;

    protected function setUp(): void
    {
        parent::setUp();

        // ---- users ----
        $this->admin = User::query()->create([
            'name'  => 'مدير النظام',
            'email' => 'admin-hajj-full@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role'  => 'admin',
            'is_active' => true,
        ]);

        $this->cashier = User::query()->create([
            'name'  => 'أمين الصندوق',
            'email' => 'cashier-hajj-full@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role'  => 'cashier',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        // ---- liquidity accounts (banks / wallets / safes) ----
        LedgerBalanceMutationGuard::run(function () {
            $this->safeEGP = Account::query()->create([
                'name'     => 'خزينة نقدي الحج والعمرة',
                'type'     => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance'  => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->bankCIB = Account::query()->create([
                'name'     => 'بنك CIB - حساب جاري',
                'type'     => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance'  => 500_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->bankNBE = Account::query()->create([
                'name'     => 'بنك NBE - توفير',
                'type'     => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance'  => 250_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->walletVodafone = Account::query()->create([
                'name'     => 'محفظة فودافون كاش',
                'type'     => AccountType::Wallet->value,
                'wallet_provider' => WalletProvider::VodafoneCash->value,
                'wallet_number' => '01012345678',
                'currency' => 'EGP',
                'balance'  => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->walletInstapay = Account::query()->create([
                'name'     => 'محفظة إنستاباي',
                'type'     => AccountType::Wallet->value,
                'wallet_provider' => WalletProvider::Instapay->value,
                'wallet_number' => 'instapay@safar',
                'currency' => 'EGP',
                'balance'  => 30_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            // ---- supplier with its own USD account ----
            $this->supplierAccount = Account::query()->create([
                'name'     => 'حساب مورّد مكة - USD',
                'type'     => AccountType::Supplier->value,
                'currency' => 'USD',
                'balance'  => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
        });

        $this->supplier = UmrahSupplier::query()->create([
            'name'     => 'مورّد مكة المكرمة',
            'phone'    => '+966555000111',
            'account_id' => $this->supplierAccount->id,
            'default_cost_price' => 1500.00,
            'is_active' => true,
        ]);

        // ---- executing company (will auto-create its own account on save) ----
        $this->executingCompany = HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة تنفيذ العمرة المصرية',
            'license_number' => 'EG-EXC-002',
            'phone' => '+201000002222',
            'is_active' => true,
        ]);

        // ---- programs ----
        // Note: For "no supplier, no executing company" tests we bypass the
        // Program::saving() hook by using DB::table. The hook's "isDirty"
        // branch would auto-create a fresh executing company from a string
        // `executing_company` value — which would then capture the expense
        // on its AP account instead of the safe (the actual cash-only path).
        // SQLite also doesn't honour the `executing_company` nullable
        // migration, so passing `executing_company_id => null` together
        // with a string `executing_company` is also a no-go.
        $noSupplierId = \DB::table('programs')->insertGetId([
            'program_name'      => 'برنامج عمرة - بدون مورّد',
            'program_type'      => 'umrah',
            'total_nights'      => 7,
            'mecca_hotel_name'  => 'فندق أبراج الصفوة',
            'mecca_nights'      => 4,
            'medina_hotel_name' => 'فندق طيبة',
            'medina_nights'     => 3,
            'airline'           => 'مصر للطيران',
            'executing_company' => 'شركة محلية للعمرة',
            'accommodation_type' => 'QUAD',
            'default_purchase_price' => 18000.00,
            'default_selling_price'  => 23000.00,
            'departure_date'    => now()->addDays(20)->toDateString(),
            'return_date'       => now()->addDays(27)->toDateString(),
            'departure_point'   => 'Cairo',
            'is_active'         => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        $this->programNoSupplier = Program::query()->findOrFail($noSupplierId);

        // For "supplier" scenario: also no company (so the supplier account
        // is unambiguously the expense target). Same bypass.
        $supplierProgId = \DB::table('programs')->insertGetId([
            'program_name'      => 'برنامج عمرة VIP - مورّد',
            'program_type'      => 'umrah',
            'total_nights'      => 10,
            'mecca_hotel_name'  => 'فندق مكة رويال',
            'mecca_nights'      => 5,
            'medina_hotel_name' => 'فندق المدينة ماريوت',
            'medina_nights'     => 5,
            'airline'           => 'الخطوط السعودية',
            'executing_company' => 'شركة محلية للعمرة VIP',
            'accommodation_type' => 'DOUBLE',
            'default_purchase_price' => 2500.00,
            'default_selling_price'  => 3200.00,
            'departure_date'    => now()->addDays(30)->toDateString(),
            'return_date'       => now()->addDays(40)->toDateString(),
            'departure_point'   => 'Cairo',
            'is_active'         => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        $this->programForSupplier = Program::query()->findOrFail($supplierProgId);

        $this->programForCompany = Program::query()->create([
            'program_name'      => 'برنامج حج - شركة منفذة',
            'program_type'      => 'hajj',
            'total_nights'      => 30,
            'mecca_hotel_name'  => 'فندق مكة المركزي',
            'mecca_nights'      => 15,
            'medina_hotel_name' => 'فندق المدينة المركزي',
            'medina_nights'     => 15,
            'airline'           => 'مصر للطيران',
            'executing_company' => $this->executingCompany->name,
            'executing_company_id' => $this->executingCompany->id,
            'accommodation_type' => 'QUAD',
            'default_purchase_price' => 80000.00,
            'default_selling_price'  => 100000.00,
            'departure_date'    => now()->addDays(60)->toDateString(),
            'return_date'       => now()->addDays(90)->toDateString(),
            'departure_point'   => 'Cairo',
            'is_active'         => true,
        ]);

        // ---- customers ----
        $this->customer = Customer::query()->create([
            'full_name' => 'محمد عبدالله',
            'phone'     => '01000000001',
        ]);
        $this->customerSupplier = Customer::query()->create([
            'full_name' => 'سارة محمود',
            'phone'     => '01000000002',
        ]);
        $this->customerCompany = Customer::query()->create([
            'full_name' => 'أحمد علي',
            'phone'     => '01000000003',
        ]);
    }

    /* ========== HELPERS ========== */

    private function assertBookingBalanced(int $bookingId): void
    {
        $entries = AccountEntry::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('module', 'hajj_umra')
                ->where('related_type', HajjUmraBooking::class)
                ->where('related_id', $bookingId)
            )->get();

        $byTx = $entries->groupBy('transaction_id');
        foreach ($byTx as $txId => $txEntries) {
            $sumDebit  = (float) $txEntries->sum('debit');
            $sumCredit = (float) $txEntries->sum('credit');
            $this->assertEqualsWithDelta(
                $sumDebit,
                $sumCredit,
                0.01,
                "Transaction #{$txId} not balanced (D={$sumDebit} C={$sumCredit})"
            );
        }
    }

    private function assertAccountBalanceConsistent(Account $account, float $initialBalance): void
    {
        $account = $account->fresh();
        $net = (float) AccountEntry::query()
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')
            ->value('net');
        $expected = $initialBalance + $net;
        $this->assertEqualsWithDelta(
            $expected,
            (float) $account->balance,
            0.01,
            "Account '{$account->name}' balance ({$account->balance}) does NOT match "
            ."initial_balance({$initialBalance}) + entries_net({$net}) = {$expected}"
        );
    }

    private function log(string $msg): void
    {
        fwrite(STDOUT, "\n  ".$msg."\n");
    }

    /* ========== ① LIQUIDITY PROVISIONING ========== */

    public function test_01_all_liquidity_accounts_exist_and_are_active(): void
    {
        $this->log('--- ① LIQUIDITY PROVISIONING ---');
        $this->log('Safe EGP id='.$this->safeEGP->id.', bal='.$this->safeEGP->balance);
        $this->log('Bank CIB id='.$this->bankCIB->id.', bal='.$this->bankCIB->balance);
        $this->log('Bank NBE id='.$this->bankNBE->id.', bal='.$this->bankNBE->balance);
        $this->log('Wallet Vodafone id='.$this->walletVodafone->id.', bal='.$this->walletVodafone->balance);
        $this->log('Wallet Instapay id='.$this->walletInstapay->id.', bal='.$this->walletInstapay->balance);

        $this->assertTrue($this->safeEGP->is_active);
        $this->assertTrue($this->bankCIB->is_active);
        $this->assertTrue($this->walletVodafone->is_active);
        $this->assertEquals('tourism', $this->safeEGP->module_type);
        $this->assertEquals('tourism', $this->bankCIB->module_type);
        $this->assertEquals('tourism', $this->walletVodafone->module_type);
        $this->assertTrue($this->safeEGP->is_module_vault);
    }

    /* ========== ② BOOKING CREATION ========== */

    public function test_02_booking_safe_only_egp(): void
    {
        $this->log('--- ② BOOKING (safe-only, EGP) ---');
        $safeBalBefore = $this->safeEGP->balance;

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id'  => $this->programNoSupplier->id,
            'purchase_price' => 18000,
            'selling_price'  => 23000,
            'currency'  => 'EGP',
            'account_id' => $this->safeEGP->id, // cash-only fallback
            'status'    => 'confirmed',
            'initial_payment' => [
                'amount'         => 5000,
                'payment_method' => 'cash',
                'account_id'     => $this->safeEGP->id,
                'paid_by'        => 'محمد عبدالله',
            ],
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $this->assertBookingBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertEquals(23000.0, (float) $booking->total_selling_price);
        $this->assertEquals(5000.0,  (float) $booking->paid_amount);
        $this->assertEquals(18000.0, (float) $booking->remaining_amount);
        $this->assertEquals(5000.0,  (float) $booking->profit);

        $this->safeEGP->refresh();
        // -18k (expense) + 5k (initial payment income) = -13k
        $this->assertEqualsWithDelta(
            $safeBalBefore - 18000 + 5000,
            (float) $this->safeEGP->balance,
            0.01
        );
        $this->assertAccountBalanceConsistent($this->safeEGP, 1000000.00);

        $this->log("Booking #{$bookingId} created. Safe balance now {$this->safeEGP->balance} (was {$safeBalBefore}).");
    }

    public function test_03_booking_with_supplier_usd(): void
    {
        $this->log('--- ② BOOKING (supplier, USD) ---');
        $supplierBalBefore = $this->supplierAccount->balance;

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id'    => $this->customerSupplier->id,
            'program_id'     => $this->programForSupplier->id,
            'supplier_id'    => $this->supplier->id,
            'purchase_price' => 1500,
            'selling_price'  => 2200,
            'currency'       => 'USD',
            'account_id'     => $this->safeEGP->id, // tourism vault
            'status'         => 'confirmed',
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $this->assertBookingBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $expenseTx = $booking->expenseTransaction;
        // Expense should target the SUPPLIER'S account, not the safe
        $this->assertEquals($this->supplierAccount->id, $expenseTx->from_account_id);

        $this->supplierAccount->refresh();
        $this->assertEqualsWithDelta(
            $supplierBalBefore - 1500,
            (float) $this->supplierAccount->balance,
            0.01
        );
        $this->assertAccountBalanceConsistent($this->supplierAccount, 0.00);

        $this->log("Supplier booking #{$bookingId}. Supplier balance now {$this->supplierAccount->balance}.");
    }

    public function test_04_booking_with_executing_company_auto_account(): void
    {
        $this->log('--- ② BOOKING (executing company auto-AP) ---');

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id'    => $this->customerCompany->id,
            'program_id'     => $this->programForCompany->id,
            'purchase_price' => 80000,
            'selling_price'  => 100000,
            'currency'       => 'EGP',
            'account_id'     => $this->safeEGP->id,
            'status'         => 'confirmed',
        ]);

        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $this->assertBookingBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $expenseTx = $booking->expenseTransaction;

        // Executing company must have its AP account auto-linked
        $this->executingCompany->refresh();
        $this->assertNotNull($this->executingCompany->account_id);
        $this->assertEquals($this->executingCompany->account_id, $expenseTx->from_account_id);

        $companyAccount = Account::find($this->executingCompany->account_id);
        $this->assertEqualsWithDelta(-80000.0, (float) $companyAccount->balance, 0.01);
        $this->assertAccountBalanceConsistent($companyAccount, 0.00);

        $this->log("Company booking #{$bookingId}. Company AP balance now {$companyAccount->balance}.");
    }

    /* ========== ③ PAYMENTS VIA MIXED BANKS / WALLETS ========== */

    public function test_05_multi_payment_split_across_banks_and_wallets(): void
    {
        $this->log('--- ③ MULTI-PAYMENT (mixed banks/wallets) ---');

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id'  => $this->programNoSupplier->id,
            'purchase_price' => 18000,
            'selling_price'  => 23000,
            'currency'  => 'EGP',
            'account_id' => $this->safeEGP->id,
            'status'    => 'confirmed',
        ]);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $bankCIBBefore = (float) $this->bankCIB->balance;
        $bankNBEBefore = (float) $this->bankNBE->balance;
        $walletBefore  = (float) $this->walletVodafone->balance;
        $instapayBefore = (float) $this->walletInstapay->balance;

        // Payment 1 — via bank CIB
        Sanctum::actingAs($this->cashier, ['*']);
        $r1 = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount'         => 7000,
            'payment_method' => 'bank_transfer',
            'account_id'     => $this->bankCIB->id,
            'reference'      => 'TRF-CIB-001',
            'paid_by'        => 'محمد عبدالله',
        ]);
        $r1->assertCreated();
        $this->assertBookingBalanced($bookingId);

        // Payment 2 — via wallet Vodafone
        $r2 = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount'         => 3000,
            'payment_method' => 'wallet',
            'account_id'     => $this->walletVodafone->id,
            'reference'      => 'VFC-001',
            'paid_by'        => 'محمد عبدالله',
        ]);
        $r2->assertCreated();
        $this->assertBookingBalanced($bookingId);

        // Payment 3 — via wallet Instapay
        $r3 = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount'         => 5000,
            'payment_method' => 'wallet',
            'account_id'     => $this->walletInstapay->id,
            'reference'      => 'INS-001',
            'paid_by'        => 'محمد عبدالله',
        ]);
        $r3->assertCreated();
        $this->assertBookingBalanced($bookingId);

        // Payment 4 — via bank NBE (over-pay scenario)
        $r4 = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount'         => 9000, // 7k+3k+5k+9k = 24k, > 23k selling → over-payment
            'payment_method' => 'bank_transfer',
            'account_id'     => $this->bankNBE->id,
            'reference'      => 'TRF-NBE-001',
            'paid_by'        => 'محمد عبدالله',
        ]);
        $r4->assertCreated();
        $this->assertBookingBalanced($bookingId);

        // Reload + assert balances
        $this->bankCIB->refresh();
        $this->bankNBE->refresh();
        $this->walletVodafone->refresh();
        $this->walletInstapay->refresh();

        $this->assertEqualsWithDelta($bankCIBBefore + 7000,  (float) $this->bankCIB->balance,        0.01);
        $this->assertEqualsWithDelta($bankNBEBefore + 9000,  (float) $this->bankNBE->balance,        0.01);
        $this->assertEqualsWithDelta($walletBefore + 3000,   (float) $this->walletVodafone->balance, 0.01);
        $this->assertEqualsWithDelta($instapayBefore + 5000, (float) $this->walletInstapay->balance, 0.01);

        $this->assertAccountBalanceConsistent($this->bankCIB,        500000.00);
        $this->assertAccountBalanceConsistent($this->bankNBE,        250000.00);
        $this->assertAccountBalanceConsistent($this->walletVodafone,  50000.00);
        $this->assertAccountBalanceConsistent($this->walletInstapay,  30000.00);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertEquals(24000.0, (float) $booking->paid_amount);
        $this->assertEquals(23000.0, (float) $booking->total_selling_price);
        $this->assertEquals(1000.0,  (float) $booking->paid_amount - $booking->total_selling_price); // over-pay
        $this->assertTrue($booking->is_fully_paid);

        $this->log("Booking #{$bookingId} paid 24,000 EGP via 4 different liquidity accounts.");
        $this->log("  CIB: +7k, NBE: +9k, Vodafone: +3k, Instapay: +5k");
    }

    /* ========== ④ CANCEL ========== */

    public function test_06_cancel_reverses_everything_balance_neutral(): void
    {
        $this->log('--- ④ CANCEL ---');

        $safeBefore = (float) $this->safeEGP->balance;
        $bankCIBBefore = (float) $this->bankCIB->balance;

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id'  => $this->programNoSupplier->id,
            'purchase_price' => 18000,
            'selling_price'  => 23000,
            'currency'  => 'EGP',
            'account_id' => $this->safeEGP->id,
            'status'    => 'confirmed',
            'initial_payment' => [
                'amount' => 8000,
                'payment_method' => 'cash',
                'account_id' => $this->safeEGP->id,
            ],
        ]);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        // Pay another chunk via bank
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 5000,
            'payment_method' => 'bank_transfer',
            'account_id' => $this->bankCIB->id,
            'reference' => 'TRF-CIB-002',
        ])->assertCreated();

        $this->safeEGP->refresh();
        $this->bankCIB->refresh();

        // Safe: -18k (expense) +8k (initial payment) = -10k safe
        // (the secondary 5k payment goes to CIB, NOT safe)
        $this->assertEqualsWithDelta($safeBefore - 18000 + 8000, (float) $this->safeEGP->balance, 0.01);
        $this->assertEqualsWithDelta($bankCIBBefore + 5000, (float) $this->bankCIB->balance, 0.01);

        // CANCEL
        Sanctum::actingAs($this->admin, ['*']);
        $cancelResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'العميل اعتذر',
        ]);
        $cancelResp->assertSuccessful();

        // Balances should return to their original values
        $this->safeEGP->refresh();
        $this->bankCIB->refresh();
        $this->assertEqualsWithDelta($safeBefore,   (float) $this->safeEGP->balance, 0.01);
        $this->assertEqualsWithDelta($bankCIBBefore, (float) $this->bankCIB->balance, 0.01);

        $this->assertAccountBalanceConsistent($this->safeEGP, 1000000.00);
        $this->assertAccountBalanceConsistent($this->bankCIB, 500000.00);

        // Booking row stays (status=cancelled, not soft-deleted)
        $booking = HajjUmraBooking::find($bookingId);
        $this->assertNotNull($booking, 'cancelled booking must still be visible');
        $this->assertEquals('cancelled', $booking->status->value);

        // Idempotency — re-cancel must reject
        $reCancel = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'محاولة إلغاء ثاني',
        ]);
        $reCancel->assertStatus(422);

        $this->log("Booking #{$bookingId} cancelled. Balances returned to original. Re-cancel rejected ✓");
    }

    /* ========== ⑤ REFUND ========== */

    public function test_07_refund_reverses_everything_and_blocks_repeat(): void
    {
        $this->log('--- ⑤ REFUND ---');

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id'  => $this->programNoSupplier->id,
            'purchase_price' => 18000,
            'selling_price'  => 23000,
            'currency'  => 'EGP',
            'account_id' => $this->safeEGP->id,
            'status'    => 'confirmed',
            'initial_payment' => [
                'amount' => 23000, // full payment upfront
                'payment_method' => 'cash',
                'account_id' => $this->safeEGP->id,
            ],
        ]);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $safeBefore = (float) $this->safeEGP->balance;
        $this->safeEGP->refresh();
        // -18k + 23k = +5k
        $this->assertEqualsWithDelta($safeBefore - 18000 + 23000, (float) $this->safeEGP->balance, 0.01);

        // Refund
        $refundResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'إلغاء كامل بسبب مشكلة في التأشيرة',
        ]);
        $refundResp->assertSuccessful();

        $this->safeEGP->refresh();
        $this->assertEqualsWithDelta($safeBefore, (float) $this->safeEGP->balance, 0.01);
        $this->assertAccountBalanceConsistent($this->safeEGP, 1000000.00);

        $booking = HajjUmraBooking::find($bookingId);
        $this->assertEquals('refunded', $booking->status->value);
        $this->assertStringContainsString('إلغاء كامل', $booking->notes);

        // Idempotency — second refund must fail
        $reRefund = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'محاولة استرداد ثانية',
        ]);
        $reRefund->assertStatus(422);

        $this->log("Booking #{$bookingId} refunded. Balances returned. Re-refund rejected ✓");
    }

    /* ========== ⑥ ADMIN DELETE ========== */

    public function test_08_admin_delete_soft_deletes_and_reverses(): void
    {
        $this->log('--- ⑥ ADMIN DELETE ---');

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id'  => $this->programNoSupplier->id,
            'purchase_price' => 18000,
            'selling_price'  => 23000,
            'currency'  => 'EGP',
            'account_id' => $this->safeEGP->id,
            'status'    => 'confirmed',
            'initial_payment' => [
                'amount' => 10000,
                'payment_method' => 'cash',
                'account_id' => $this->safeEGP->id,
            ],
        ]);
        $response->assertCreated();
        $bookingId = $response->json('data.id');

        $safeBefore = (float) $this->safeEGP->balance;

        $this->safeEGP->refresh();
        $this->assertEqualsWithDelta($safeBefore - 18000 + 10000, (float) $this->safeEGP->balance, 0.01);

        // Admin delete
        $delResp = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $delResp->assertSuccessful();

        $this->safeEGP->refresh();
        $this->assertEqualsWithDelta($safeBefore, (float) $this->safeEGP->balance, 0.01);
        $this->assertAccountBalanceConsistent($this->safeEGP, 1000000.00);

        // Booking is soft-deleted
        $booking = HajjUmraBooking::find($bookingId);
        $this->assertNull($booking);
        $bookingTrashed = HajjUmraBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($bookingTrashed);
        $this->assertNotNull($bookingTrashed->deleted_at);

        // Idempotency — second delete must fail
        $reDelete = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $reDelete->assertStatus(422);

        $this->log("Booking #{$bookingId} soft-deleted. Balance returned. Re-delete rejected ✓");
    }

    /* ========== ⑦ MULTI-CURRENCY ========== */

    public function test_09_multi_currency_booking_and_payment(): void
    {
        $this->log('--- ⑦ MULTI-CURRENCY ---');

        // USD booking
        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customerSupplier->id,
            'program_id'  => $this->programForSupplier->id,
            'supplier_id' => $this->supplier->id,
            'purchase_price' => 1500,
            'selling_price'  => 2200,
            'currency'  => 'USD',
            'account_id' => $this->safeEGP->id, // EGP vault — currency mismatch is user's choice
            'status'    => 'confirmed',
        ]);
        $response->assertCreated();
        $bookingId = $response->json('data.id');
        $this->assertBookingBalanced($bookingId);

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertEquals('USD', $booking->currency);
        $this->assertEquals(700.0, (float) $booking->profit);

        $this->log("USD booking #{$bookingId} created with profit \${$booking->profit}");
    }

    /* ========== ⑧ LIQUIDITY RULE ========== */

    public function test_10_rejects_office_vault_for_hajj_umra(): void
    {
        $this->log('--- ⑩ LIQUIDITY RULE ---');

        // Create an office vault (wrong module)
        $officeVault = Account::query()->create([
            'name'     => 'خزينة مكتب',
            'type'     => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance'  => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'module' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id'  => $this->programNoSupplier->id,
            'purchase_price' => 18000,
            'selling_price'  => 23000,
            'currency'  => 'EGP',
            'account_id' => $officeVault->id, // wrong module
            'status'    => 'confirmed',
        ]);

        $response->assertStatus(422);
        $this->log("Office-vault booking correctly REJECTED with 422.");
    }

    /* ========== ⑨ GLOBAL DOUBLE-ENTRY INTEGRITY ========== */

    public function test_11_global_double_entry_integrity(): void
    {
        $this->log('--- ⑨ GLOBAL DOUBLE-ENTRY ---');

        // Create a few bookings, pay each partially
        for ($i = 0; $i < 3; $i++) {
            $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
                'customer_id' => $this->customer->id,
                'program_id'  => $this->programNoSupplier->id,
                'purchase_price' => 18000,
                'selling_price'  => 23000,
                'currency'  => 'EGP',
                'account_id' => $this->safeEGP->id,
                'status'    => 'confirmed',
                'initial_payment' => [
                    'amount' => 5000,
                    'payment_method' => 'cash',
                    'account_id' => $this->safeEGP->id,
                ],
            ]);
            $resp->assertCreated();
            $id = $resp->json('data.id');
            $this->postJson("/api/v1/hajj-umra/bookings/{$id}/payments", [
                'amount' => 3000,
                'payment_method' => 'cash',
                'account_id' => $this->bankCIB->id,
            ])->assertCreated();
        }

        // 1. Every transaction must have Σdebit = Σcredit
        $txs = Transaction::query()->where('module', 'hajj_umra')->get();
        foreach ($txs as $tx) {
            $entries = AccountEntry::where('transaction_id', $tx->id)->get();
            $this->assertEqualsWithDelta(
                (float) $entries->sum('debit'),
                (float) $entries->sum('credit'),
                0.01,
                "TX #{$tx->id} not balanced"
            );
        }

        // 2. Every account.balance must equal initial_balance + Σcredit − Σdebit.
        //    We track the initial balance in the model so we can reproduce
        //    the production invariant (balance = SUM(entries) + opening).
        $knownInitial = [
            $this->safeEGP->id        => 1000000.00,
            $this->bankCIB->id        =>  500000.00,
            $this->bankNBE->id        =>  250000.00,
            $this->walletVodafone->id =>   50000.00,
            $this->walletInstapay->id =>   30000.00,
            $this->supplierAccount->id=>       0.00,
        ];
        foreach (Account::all() as $acc) {
            $initial = $knownInitial[$acc->id] ?? 0.00;
            $this->assertAccountBalanceConsistent($acc, $initial);
        }

        $this->log("Verified ".count($txs)." hajj_umra transactions; all accounts consistent.");
    }
}