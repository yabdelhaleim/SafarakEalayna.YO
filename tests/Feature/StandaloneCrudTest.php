<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Online\OnlineTransaction;
use App\Models\Online\OnlineServiceType;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Account;
use App\Models\Setting\PaymentMethod;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StandaloneCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppliers_full_crud(): void
    {
        $user = User::query()->create([
            'name' => 'Supplier Tester',
            'email' => 'supplier-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // CREATE
        $create = $this->postJson('/api/v1/suppliers', [
            'name' => 'Test Supplier',
            'type' => 'other',
            'phone' => '01001234567',
            'email' => 'supplier@example.com',
            'is_active' => true,
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'name', 'code', 'type']]);
        $supplierId = (int) $create->json('data.id');

        // INDEX
        $this->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items', 'pagination']]);

        // SHOW
        $this->getJson("/api/v1/suppliers/{$supplierId}")
            ->assertOk()
            ->assertJsonPath('data.id', $supplierId)
            ->assertJsonPath('data.name', 'Test Supplier');

        // UPDATE
        $this->putJson("/api/v1/suppliers/{$supplierId}", [
            'name' => 'Updated Supplier',
            'notes' => 'Test note',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Supplier');

        // DELETE
        $this->deleteJson("/api/v1/suppliers/{$supplierId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_users_full_crud(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'admin-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin, ['*']);

        // CREATE
        $create = $this->postJson('/api/v1/users', [
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'password123',
            'role' => 'employee',
            'is_active' => true,
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role']]);
        $userId = (int) $create->json('data.id');

        // INDEX
        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);

        // SHOW
        $this->getJson("/api/v1/users/{$userId}")
            ->assertOk()
            ->assertJsonPath('data.id', $userId);

        // UPDATE
        $this->putJson("/api/v1/users/{$userId}", [
            'name' => 'Updated User',
            'role' => 'admin',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated User');

        // DELETE — create a separate user to delete
        $target = User::query()->create([
            'name' => 'Delete Target',
            'email' => 'delete-target@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->deleteJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_online_transactions_full_crud(): void
    {
        $user = User::query()->create([
            'name' => 'Online Tx Tester',
            'email' => 'online-tx-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Dependencies: service type, customer, payment method, account
        $typeId = DB::table('online_service_types')->insertGetId([
            'code' => 'crud_test',
            'name_ar' => 'خدمة اختبار CRUD',
            'name_en' => 'CRUD Test Service',
            'is_active' => true,
            'order' => 1,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'Test Customer',
            'phone' => '01009999999',
            'status' => 'active',
        ]);

        $pm = PaymentMethod::query()->create([
            'code' => 'crud_cash',
            'name_ar' => 'نقدي CRUD',
            'name_en' => 'CRUD Cash',
            'color' => '#10B981',
            'is_active' => true,
            'order' => 0,
        ]);

        $account = Account::query()->create([
            'name' => 'Online Tx Account',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $user->id,
        ]);

        // CREATE
        $create = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $typeId,
            'customer_id' => $customer->id,
            'purchase_price' => 100,
            'selling_price' => 100,
            'payment_method' => $pm->code,
            'account_id' => $account->id,
            'status' => 'pending',
            'notes' => 'CRUD test transaction',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'purchase_price', 'selling_price', 'status']]);
        $txId = (int) $create->json('data.id');

        // INDEX
        $this->getJson('/api/v1/online/transactions?per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items', 'pagination']]);

        // SHOW
        $this->getJson("/api/v1/online/transactions/{$txId}")
            ->assertOk()
            ->assertJsonPath('data.id', $txId);

        // UPDATE
        $this->putJson("/api/v1/online/transactions/{$txId}", [
            'notes' => 'Updated notes',
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Updated notes');

        // DELETE — the model's deleting event prevents deletion, expects 422
        $this->deleteJson("/api/v1/online/transactions/{$txId}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_wallet_transactions_full_crud(): void
    {
        $user = User::query()->create([
            'name' => 'Wallet Tx Tester',
            'email' => 'wallet-tx-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Dependencies: wallet type, wallet + cash accounts
        $walletTypeId = DB::table('wallet_types')->insertGetId([
            'name' => 'CRUD Wallet Type',
            'code' => 'crud_wallet',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $walletAccount = Account::query()->create([
            'name' => 'Wallet Account',
            'type' => 'wallet',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $user->id,
        ]);

        $cashAccount = Account::query()->create([
            'name' => 'Cash Account',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'created_by' => $user->id,
        ]);

        // CREATE
        $create = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $walletTypeId,
            'customer_name' => 'Test Wallet Customer',
            'wallet_number' => '01009999999',
            'type' => 'receive',
            'amount' => 500,
            'wallet_account_id' => $walletAccount->id,
            'cash_account_id' => $cashAccount->id,
            'notes' => 'CRUD wallet transaction',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'amount', 'type', 'wallet_type' => ['id', 'name', 'code']]]);
        $txId = (int) $create->json('data.id');

        // INDEX
        $this->getJson('/api/v1/wallet/transactions?per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items', 'pagination']]);

        // SHOW
        $this->getJson("/api/v1/wallet/transactions/{$txId}")
            ->assertOk()
            ->assertJsonPath('data.id', $txId);

        // UPDATE
        $this->putJson("/api/v1/wallet/transactions/{$txId}", [
            'notes' => 'Updated wallet notes',
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Updated wallet notes');

        // DELETE
        $this->deleteJson("/api/v1/wallet/transactions/{$txId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_employee_attendances_full_crud(): void
    {
        $user = User::query()->create([
            'name' => 'Attendance Tester',
            'email' => 'attendance-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Dependencies: employee
        $employee = Employee::query()->create([
            'user_id' => $user->id,
            'full_name' => 'Test Employee',
            'salary' => 5000,
        ]);

        // CREATE
        $create = $this->postJson('/api/v1/employee/attendances', [
            'employee_id' => $employee->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'notes' => 'CRUD attendance test',
        ]);
        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'employee_id', 'attendance_date', 'status']]);
        $attendanceId = (int) $create->json('data.id');

        // INDEX
        $this->getJson('/api/v1/employee/attendances')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['items', 'pagination']]);

        // SHOW
        $this->getJson("/api/v1/employee/attendances/{$attendanceId}")
            ->assertOk()
            ->assertJsonPath('data.id', $attendanceId);

        // UPDATE
        $this->putJson("/api/v1/employee/attendances/{$attendanceId}", [
            'status' => 'absent',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'absent');

        // DELETE
        $this->deleteJson("/api/v1/employee/attendances/{$attendanceId}")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_employee_reports(): void
    {
        $user = User::query()->create([
            'name' => 'Report Tester',
            'email' => 'report-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Dependencies: employee
        Employee::query()->create([
            'user_id' => $user->id,
            'full_name' => 'Test Employee',
            'salary' => 5000,
        ]);

        // INDEX returns the overall report
        $this->getJson('/api/v1/employee/reports')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['summary', 'financials', 'attendance']]);

        // POST (store) is not supported — returns 405
        $this->postJson('/api/v1/employee/reports', [
            'employee_id' => 1,
            'title' => 'Test Report',
            'content' => 'Report content',
            'type' => 'monthly',
            'date' => now()->toDateString(),
        ])
            ->assertStatus(405)
            ->assertJsonPath('success', false);

        // PUT (update) is not supported — returns 405
        $this->putJson('/api/v1/employee/reports/1', [
            'content' => 'Updated content',
        ])
            ->assertStatus(405)
            ->assertJsonPath('success', false);

        // DELETE (destroy) is not supported — returns 405
        $this->deleteJson('/api/v1/employee/reports/1')
            ->assertStatus(405)
            ->assertJsonPath('success', false);
    }
}
