<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\ApprovalActionType;
use App\Enums\ApprovalStatus;
use App\Models\Account;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Finance\ApprovalService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Approval Workflow coverage.
 *
 * Coverage targets:
 *   - ApprovalController apiResource CRUD endpoints (index/store/show/update/destroy)
 *   - ApprovalService::createApprovalRequest end-to-end (DB + audit log)
 *   - Pending/Approved scopes
 *
 * Notes (Gap acknowledged — see GAP_ANALYSIS.md):
 *   1. approve() and reject() controller methods are NOT routed in routes/api.php,
 *      so the HTTP layer cannot reach them. They are documented as a gap.
 *   2. ApprovalService::approve()/reject() compare `$workflow->status !==
 *      ApprovalStatus::PENDING` strictly. Because `status` is stored as a STRING
 *      (no enum cast on the model), the strict comparison always fails — every
 *      approve/reject call throws. Tests here intentionally pin that behaviour
 *      so any future fix is caught.
 */
class CoreAccountsApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Account $approvableStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Approvals Admin',
            'email' => 'admin@approvals.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        // A real Model so the MorphTo can resolve when the controller
        // eager-loads `approvable` on the workflow.
        $this->approvableStub = $this->seedAccount(['name' => 'AP_StubAccount']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'Approval EGP',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    private function seedWorkflow(array $overrides = []): ApprovalWorkflow
    {
        return ApprovalWorkflow::create(array_merge([
            'approvable_type' => Account::class,
            'approvable_id' => $this->approvableStub->id,
            'status' => ApprovalStatus::PENDING->value,
            'action_type' => ApprovalActionType::TRANSFER->value,
            'requested_by' => $this->admin->id,
        ], $overrides));
    }

    // ───────────── apiResource CRUD ─────────────

    public function test_AP_01_index_lists_all_workflows(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->seedWorkflow(['notes' => "row {$i}"]);
        }

        $r = $this->getJson('/api/v1/finance/approvals');

        $r->assertOk()->assertJsonStructure([
            'success',
            'data' => ['*' => [
                'id', 'approvable_type', 'approvable_id', 'status',
                'action_type', 'requested_by', 'created_at',
            ]],
        ]);
        $this->assertCount(4, $r->json('data'));
    }

    public function test_AP_02_store_creates_workflow_default_status_pending(): void
    {
        $r = $this->postJson('/api/v1/finance/approvals', [
            'approvable_type' => Account::class,
            'approvable_id' => $this->approvableStub->id,
            'action_type' => ApprovalActionType::TRANSFER->value,
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('data.status', ApprovalStatus::PENDING->value);

        $this->assertDatabaseHas('approval_workflows', [
            'approvable_id' => $this->approvableStub->id,
            'status' => ApprovalStatus::PENDING->value,
            'requested_by' => $this->admin->id,
        ]);
    }

    public function test_AP_03_show_returns_workflow_with_requested_by(): void
    {
        $wf = $this->seedWorkflow();

        $r = $this->getJson("/api/v1/finance/approvals/{$wf->id}");

        $r->assertOk()
            ->assertJsonPath('data.id', $wf->id)
            ->assertJsonStructure(['data' => ['requested_by']]);
    }

    public function test_AP_04_update_can_change_status_and_notes(): void
    {
        $wf = $this->seedWorkflow();

        $r = $this->patchJson("/api/v1/finance/approvals/{$wf->id}", [
            'status' => ApprovalStatus::CANCELLED->value,
            'notes' => 'cancelled by requester',
        ]);

        $r->assertOk()->assertJsonPath('data.status', ApprovalStatus::CANCELLED->value);
        $this->assertSame('cancelled by requester', $wf->fresh()->notes);
    }

    public function test_AP_05_destroy_deletes_row(): void
    {
        $wf = $this->seedWorkflow();
        $this->deleteJson("/api/v1/finance/approvals/{$wf->id}")->assertOk();
        $this->assertDatabaseMissing('approval_workflows', ['id' => $wf->id]);
    }

    public function test_AP_06_validation_create_requires_approvable_type_id_and_action_type(): void
    {
        $this->postJson('/api/v1/finance/approvals', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['approvable_type', 'approvable_id', 'action_type']);
    }

    public function test_AP_07_show_unknown_approval_returns_404(): void
    {
        $this->getJson('/api/v1/finance/approvals/9999')->assertStatus(404);
    }

    public function test_AP_08_pending_scope_returns_only_pending_rows(): void
    {
        $this->seedWorkflow(['status' => ApprovalStatus::PENDING->value, 'notes' => 'p1']);
        $this->seedWorkflow(['status' => ApprovalStatus::PENDING->value, 'notes' => 'p2']);
        $this->seedWorkflow(['status' => ApprovalStatus::APPROVED->value, 'notes' => 'a1']);
        $this->seedWorkflow(['status' => ApprovalStatus::REJECTED->value, 'notes' => 'r1']);

        $this->assertSame(2, ApprovalWorkflow::pending()->count());
    }

    public function test_AP_09_approved_scope_returns_only_approved_rows(): void
    {
        $this->seedWorkflow(['status' => ApprovalStatus::PENDING->value, 'notes' => 'p1']);
        $this->seedWorkflow(['status' => ApprovalStatus::APPROVED->value, 'notes' => 'a1']);
        $this->seedWorkflow(['status' => ApprovalStatus::APPROVED->value, 'notes' => 'a2']);

        $this->assertSame(2, ApprovalWorkflow::approved()->count());
    }

    // ───────────── Service: createApprovalRequest ─────────────

    public function test_AP_10_create_approval_request_writes_workflow_and_audit_log(): void
    {
        /** @var ApprovalService $svc */
        $svc = app(ApprovalService::class);
        $approvable = $this->seedAccount(['name' => 'AP_AccountStub_10']);

        $workflow = $svc->createApprovalRequest($approvable, ApprovalActionType::TRANSFER);

        $this->assertSame(ApprovalStatus::PENDING, $workflow->status);
        $this->assertSame(ApprovalActionType::TRANSFER->value, $workflow->action_type);
        $this->assertSame($this->admin->id, $workflow->requested_by);

        $this->assertDatabaseHas('approval_workflows', [
            'id' => $workflow->id,
            'approvable_type' => Account::class,
            'approvable_id' => $approvable->id,
            'status' => ApprovalStatus::PENDING->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_approval_request',
            'model_type' => ApprovalWorkflow::class,
            'model_id' => $workflow->id,
        ]);
    }

    public function test_AP_11_create_approval_request_persists_each_action_type(): void
    {
        // The DB enum limits action_type to
        //   ['booking','transfer','currency_conversion','payment'].
        // ApprovalActionType::REFUND exists in the PHP enum but is rejected
        // by the column CHECK constraint — intentional gap to record here.
        /** @var ApprovalService $svc */
        $svc = app(ApprovalService::class);

        $acc1 = $this->seedAccount(['name' => 'AP_Stub_11a']);
        $acc2 = $this->seedAccount(['name' => 'AP_Stub_11b']);
        $acc3 = $this->seedAccount(['name' => 'AP_Stub_11c']);

        $wf1 = $svc->createApprovalRequest($acc1, ApprovalActionType::BOOKING);
        $wf2 = $svc->createApprovalRequest($acc2, ApprovalActionType::PAYMENT);
        $wf3 = $svc->createApprovalRequest($acc3, ApprovalActionType::CURRENCY_CONVERSION);

        $this->assertSame(ApprovalActionType::BOOKING->value, $wf1->action_type);
        $this->assertSame(ApprovalActionType::PAYMENT->value, $wf2->action_type);
        $this->assertSame(ApprovalActionType::CURRENCY_CONVERSION->value, $wf3->action_type);
    }

    // ───────────── Latent bug pinning ─────────────
    // ApprovalService::approve()/reject() compare `$workflow->status !==
    // ApprovalStatus::PENDING` strictly. Because the model does not cast
    // `status` to the enum, `$workflow->status` is the string 'pending', and
    // the strict comparison ALWAYS fails — every approve/reject call throws.
    // These tests pin that behaviour so any future fix is caught.
    // ─────────────────────────────────────────────

    public function test_AP_12_latent_bug_approve_throws_due_to_strict_enum_comparison(): void
    {
        $wf = $this->seedWorkflow();

        /** @var ApprovalService $svc */
        $svc = app(ApprovalService::class);

        $this->expectException(\Throwable::class);
        $svc->approve($wf->id);
    }

    public function test_AP_13_latent_bug_reject_throws_due_to_strict_enum_comparison(): void
    {
        $wf = $this->seedWorkflow();

        /** @var ApprovalService $svc */
        $svc = app(ApprovalService::class);

        $this->expectException(\Throwable::class);
        $svc->reject($wf->id, 'no reason');
    }

    public function test_AP_14_latent_bug_approve_throws_on_already_rejected_workflow(): void
    {
        $wf = $this->seedWorkflow(['status' => ApprovalStatus::REJECTED->value]);

        /** @var ApprovalService $svc */
        $svc = app(ApprovalService::class);

        $this->expectException(\Throwable::class);
        $svc->approve($wf->id);
    }

    // ───────────── Audit log expectations ─────────────

    public function test_AP_15_create_approval_request_writes_audit_log_with_metadata(): void
    {
        /** @var ApprovalService $svc */
        $svc = app(ApprovalService::class);
        $approvable = $this->seedAccount(['name' => 'AP_Stub_15']);

        $wf = $svc->createApprovalRequest($approvable, ApprovalActionType::TRANSFER);

        $log = AuditLog::query()
            ->where('action', 'create_approval_request')
            ->where('model_id', $wf->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(ApprovalActionType::TRANSFER->value, $log->new_values['action_type'] ?? null);
        $this->assertSame($approvable->id, $log->new_values['approvable_id'] ?? null);
    }
}
