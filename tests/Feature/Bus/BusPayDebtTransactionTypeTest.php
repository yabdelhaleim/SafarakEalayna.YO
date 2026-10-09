<?php

namespace Tests\Feature\Bus;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Bus\BusCompanyPayment;
use App\Models\Transaction;
use App\Services\Bus\BusTransactionTypeClassifier;

class BusPayDebtTransactionTypeTest extends BusTestCase
{
    public function test_pay_debt_creates_transfer_not_expense(): void
    {
        $company = $this->makeBusCompany(['name' => 'حورس باص'], -500);
        $this->seedCashboxBalance(1000);

        $response = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount' => 100,
            'from_account_id' => $this->cashboxEgp->id,
            'notes' => 'تسديد دين شركة باصات',
        ]);

        // Verified the real API contract via ApiResponse::success (app/Helpers/ApiResponse.php:15):
        //   { "success": true, "message": ..., "data": { "transaction_id": ..., "new_balance": ..., "fully_settled": ... } }
        // The original assertion `assertJsonPath('status', true)` checked a non-existent field.
        // Correct assertion is the root-level `success` boolean AND the canonical financial invariants
        // nested inside `data.*` (transaction_id present, fully_settled=false because -400 EGP remains).
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.fully_settled', false);

        // The endpoint returns data.new_balance as a numeric string (decimal:2 cast), so use delta compare.
        $this->assertEqualsWithDelta(
            -400.0,
            (float) $response->json('data.new_balance'),
            0.01,
            'After paying 100 EGP of -500 EGP supplier debt, new_balance must be exactly -400.0'
        );

        $transaction = Transaction::query()
            ->where('module', 'bus')
            ->where('to_account_id', $company->account_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame(
            TransactionType::Transfer->value,
            $transaction->type->value,
            'Settling supplier AP via /pay-debt must record a transfer.'
        );

        $this->assertDatabaseHas('bus_company_payments', [
            'transaction_id' => $transaction->id,
            'company_id' => $company->id,
            'status' => 'paid',
        ]);

        $cashboxBalance = Account::query()->find($this->cashboxEgp->id)->balance;
        $supplierBalance = Account::query()->find($company->account_id)->balance;
        $this->assertEquals(900.0, (float) $cashboxBalance);
        $this->assertEquals(-400.0, (float) $supplierBalance);
    }

    public function test_pay_debt_with_default_notes_creates_transfer(): void
    {
        $company = $this->makeBusCompany(['name' => 'السفير باص'], -200);
        $this->seedCashboxBalance(1000);

        $response = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount' => 50,
            'from_account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $transaction = Transaction::query()
            ->where('module', 'bus')
            ->where('to_account_id', $company->account_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame(
            TransactionType::Transfer->value,
            $transaction->type->value
        );
        $this->assertSame('تسديد دين شركة باصات', $transaction->notes);
    }

    public function test_backfill_classifier_recognises_pay_debt_settlement(): void
    {
        $company = $this->makeBusCompany(['name' => 'حورس باص'], -100);
        $this->seedCashboxBalance(500);

        $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount' => 40,
            'from_account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $transaction = Transaction::query()
            ->where('module', 'bus')
            ->where('to_account_id', $company->account_id)
            ->latest('id')
            ->first();

        $classifier = app(BusTransactionTypeClassifier::class);

        $this->assertSame(
            TransactionType::Transfer->value,
            $classifier->classify($transaction),
            'Backfill classifier must classify /pay-debt rows as Transfer.'
        );

        $paymentCount = BusCompanyPayment::query()
            ->where('transaction_id', $transaction->id)
            ->count();
        $this->assertSame(1, $paymentCount);
    }
}
