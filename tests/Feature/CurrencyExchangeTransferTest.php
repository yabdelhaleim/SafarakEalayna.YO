<?php

namespace Tests\Feature;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Transfer;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyExchangeTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_currency_transfer_records_transfer_row_and_balances(): void
    {
        $user = User::create([
            'name' => 'FX Test',
            'email' => 'fx-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        Employee::create(['user_id' => $user->id, 'status' => 'active']);

        $egp = Account::create([
            'name' => 'EGP vault test',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 20_000,
            'is_active' => true,
            'owner_type' => 'owner',
            'module_type' => 'tourism',
            'created_by' => $user->id,
        ]);

        $kwd = Account::create([
            'name' => 'KWD vault test',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'owner',
            'module_type' => 'tourism',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $egp->id,
            'to_account_id' => $kwd->id,
            'amount' => 17_500,
            'converted_amount' => 100,
            'module' => TransactionModule::General->value,
            'notes' => 'شراء دينار من الصرافة',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.from_currency', 'EGP');
        $response->assertJsonPath('data.to_currency', 'KWD');

        $egp->refresh();
        $kwd->refresh();

        $this->assertSame(2500.0, (float) $egp->balance);
        $this->assertSame(100.0, (float) $kwd->balance);

        $transfer = Transfer::query()->first();
        $this->assertNotNull($transfer);
        $this->assertSame('EGP', $transfer->from_currency);
        $this->assertSame('KWD', $transfer->to_currency);
        $this->assertSame(100.0, (float) $transfer->converted_amount);
        $this->assertSame(175.0, (float) $transfer->exchange_rate);
    }

    public function test_same_currency_transfer_rejects_mismatched_converted_amount(): void
    {
        $user = User::create([
            'name' => 'FX Test 2',
            'email' => 'fx2-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        Employee::create(['user_id' => $user->id, 'status' => 'active']);

        $a = Account::create([
            'name' => 'EGP A',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
            'owner_type' => 'owner',
            'module_type' => 'tourism',
            'created_by' => $user->id,
        ]);

        $b = Account::create([
            'name' => 'EGP B',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'owner',
            'module_type' => 'tourism',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $a->id,
            'to_account_id' => $b->id,
            'amount' => 100,
            'converted_amount' => 99,
        ]);

        $response->assertStatus(422);
    }
}
