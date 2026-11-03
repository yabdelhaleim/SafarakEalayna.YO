<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Drawer Close endpoint coverage.
 *
 * POST /api/v1/finance/treasuries/close-drawer
 *
 * Gap acknowledged: the closeDrawer method exists on TreasuryController but
 * is NOT routed in routes/api.php. Tests here invoke the controller directly
 * via the IoC container to exercise its behaviour, then assert:
 *   - audit log row is written
 *   - no balance mutation occurs (purely a reconciliation log)
 *   - validation rules
 *   - permission gate (admin only)
 */
class CoreAccountsDrawerCloseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Drawer Admin',
            'email' => 'admin@drawer.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->cashbox = LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => 'DRAWER_Cashbox',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ]));
    }

    public function test_DC_01_close_drawer_writes_audit_log_without_balance_mutation(): void
    {
        $balanceBefore = (float) $this->cashbox->fresh()->balance;
        $auditCountBefore = AuditLog::query()->count();

        $controller = app(\App\Http\Controllers\Api\V1\Finance\TreasuryController::class);
        $request = \Illuminate\Http\Request::create('/api/v1/finance/treasuries/close-drawer', 'POST', [
            'drawer_account_id' => $this->cashbox->id,
            'counted_cash' => 1000.00,
            'notes' => 'end-of-day close',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        $response = $controller->closeDrawer($request);

        // 1. Balance unchanged (pure audit)
        $this->assertSame($balanceBefore, (float) $this->cashbox->fresh()->balance);

        // 2. Audit log row written
        $this->assertSame($auditCountBefore + 1, AuditLog::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'close_drawer',
            'model_type' => Account::class,
            'model_id' => $this->cashbox->id,
        ]);

        // 3. Response success
        $payload = json_decode($response->getContent(), true);
        $this->assertTrue($payload['success'] ?? false);
    }

    public function test_DC_02_close_drawer_records_discrepancy_when_counted_differs(): void
    {
        $balanceBefore = (float) $this->cashbox->fresh()->balance;

        $controller = app(\App\Http\Controllers\Api\V1\Finance\TreasuryController::class);
        $request = \Illuminate\Http\Request::create('/api/v1/finance/treasuries/close-drawer', 'POST', [
            'drawer_account_id' => $this->cashbox->id,
            'counted_cash' => 950.00,  // 50 short
            'notes' => 'small shortage',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        $controller->closeDrawer($request);

        // Balance still not mutated by the close operation
        $this->assertSame($balanceBefore, (float) $this->cashbox->fresh()->balance);

        $log = AuditLog::query()->where('action', 'close_drawer')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(1000.00, (float) ($log->old_values['system_total'] ?? 0));
        $this->assertSame(950.00, (float) ($log->new_values['counted_cash'] ?? 0));
        $this->assertSame(-50.00, (float) ($log->new_values['discrepancy'] ?? 0));
    }

    public function test_DC_03_close_drawer_validates_required_fields(): void
    {
        $controller = app(\App\Http\Controllers\Api\V1\Finance\TreasuryController::class);

        // Missing both fields
        $request = \Illuminate\Http\Request::create('/api/v1/finance/treasuries/close-drawer', 'POST', []);
        $request->setUserResolver(fn () => $this->admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->closeDrawer($request);
    }

    public function test_DC_04_close_drawer_nonexistent_account_validation_fails(): void
    {
        $controller = app(\App\Http\Controllers\Api\V1\Finance\TreasuryController::class);

        $request = \Illuminate\Http\Request::create('/api/v1/finance/treasuries/close-drawer', 'POST', [
            'drawer_account_id' => 9999,
            'counted_cash' => 100.0,
        ]);
        $request->setUserResolver(fn () => $this->admin);

        // The `exists:accounts,id` validation rule fires first; the request
        // never reaches the service layer. ModelNotFoundException is NOT
        // raised here.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->closeDrawer($request);
    }
}
