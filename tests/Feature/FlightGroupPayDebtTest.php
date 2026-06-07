<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlightGroupPayDebtTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasuryAccount;

    protected FlightGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasuryAccount = Account::query()->create([
            'name' => 'الخزينة الرئيسية',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $this->user->id,
        ]);

        $this->group = FlightGroup::query()->create([
            'name' => 'مجموعة اختبار',
            'code' => 'TST',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        FlightGroupTransaction::query()->create([
            'flight_group_id' => $this->group->id,
            'type' => 'debt',
            'amount' => 10000.00,
            'notes' => 'شراء تذكرة بالأجل',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_group_payment_reduces_outstanding_balance(): void
    {
        $response = $this->postJson("/api/v1/flight/groups/{$this->group->id}/pay-debt", [
            'amount' => 4000.00,
            'account_id' => $this->treasuryAccount->id,
            'type' => 'payment',
            'notes' => 'سند صرف — دفع للمجموعة',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.new_balance', 6000);

        $totalDebt = $this->group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $this->group->groupTransactions()->where('type', 'payment')->sum('amount');

        $this->assertEquals(6000.0, $totalDebt - $totalPayment);
        $this->assertDatabaseHas('flight_group_transactions', [
            'flight_group_id' => $this->group->id,
            'type' => 'payment',
            'amount' => 4000.00,
        ]);
    }

    public function test_rejects_receipt_type_when_group_balance_is_payable(): void
    {
        $response = $this->postJson("/api/v1/flight/groups/{$this->group->id}/pay-debt", [
            'amount' => 1000.00,
            'account_id' => $this->treasuryAccount->id,
            'type' => 'debt',
            'notes' => 'محاولة قبض خاطئة',
        ]);

        $response->assertStatus(422);

        $totalDebt = $this->group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $this->group->groupTransactions()->where('type', 'payment')->sum('amount');

        $this->assertEquals(10000.0, $totalDebt - $totalPayment);
    }

    public function test_rejects_payment_type_when_group_balance_is_negative(): void
    {
        FlightGroupTransaction::query()->create([
            'flight_group_id' => $this->group->id,
            'type' => 'payment',
            'amount' => 12000.00,
            'notes' => 'دفع زائد',
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/flight/groups/{$this->group->id}/pay-debt", [
            'amount' => 500.00,
            'account_id' => $this->treasuryAccount->id,
            'type' => 'payment',
            'notes' => 'محاولة صرف خاطئة',
        ]);

        $response->assertStatus(422);
    }
}
