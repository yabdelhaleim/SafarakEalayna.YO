<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Finance\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Audit Log coverage.
 *
 * Coverage targets:
 *   - AuditController apiResource CRUD (index/store/show/update/destroy)
 *   - AuditService::log writes a row
 *   - AuditService::getUserAuditLog paginated list
 *   - AuditService::getModelAuditLog filtered by morph target
 *   - AuditService::getDailyReport aggregated per-date summary
 *
 * Notes (Gap acknowledged — see GAP_ANALYSIS.md):
 *   - The custom controller methods (getUserLog, getModelLog, getDailyReport)
 *     are NOT routed in routes/api.php. Tests here exercise them via the
 *     service directly.
 */
class CoreAccountsAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Audit Admin',
            'email' => 'admin@audit.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedLog(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'user_id' => $this->admin->id,
            'action' => 'test_action',
            'model_type' => User::class,
            'model_id' => $this->admin->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'old_values' => ['k' => 'old'],
            'new_values' => ['k' => 'new'],
            'notes' => 'test note',
        ], $overrides));
    }

    // ───────────── apiResource CRUD ─────────────

    public function test_AL_01_index_lists_audit_logs(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->seedLog(['action' => "log_$i"]);
        }

        $r = $this->getJson('/api/v1/finance/audits?per_page=50');

        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => ['*' => ['id', 'user_id', 'action', 'model_type', 'model_id']],
                    'pagination' => ['total', 'current_page', 'last_page'],
                ],
            ]);

        $this->assertCount(3, $r->json('data.items'));
        $this->assertSame(3, $r->json('data.pagination.total'));
    }

    public function test_AL_02_store_creates_audit_log(): void
    {
        $r = $this->postJson('/api/v1/finance/audits', [
            'action' => 'create_exchange_rate',
            'model_type' => 'App\\Models\\ExchangeRate',
            'model_id' => 1,
            'notes' => 'rate set',
        ]);

        $r->assertStatus(201)->assertJsonPath('data.action', 'create_exchange_rate');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_exchange_rate',
            'model_type' => 'App\\Models\\ExchangeRate',
            'model_id' => 1,
            'notes' => 'rate set',
        ]);
    }

    public function test_AL_03_show_returns_log_with_user_eager_loaded(): void
    {
        $log = $this->seedLog();

        $r = $this->getJson("/api/v1/finance/audits/{$log->id}");

        $r->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonStructure(['data' => ['user']]);
    }

    public function test_AL_04_update_can_change_notes_only(): void
    {
        $log = $this->seedLog();

        $r = $this->patchJson("/api/v1/finance/audits/{$log->id}", [
            'notes' => 'updated notes',
        ]);

        $r->assertOk()->assertJsonPath('data.notes', 'updated notes');
        $this->assertSame('updated notes', $log->fresh()->notes);
    }

    public function test_AL_05_destroy_deletes_audit_log(): void
    {
        $log = $this->seedLog();
        $this->deleteJson("/api/v1/finance/audits/{$log->id}")->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['id' => $log->id]);
    }

    public function test_AL_06_show_unknown_audit_returns_404(): void
    {
        $this->getJson('/api/v1/finance/audits/9999')->assertStatus(404);
    }

    public function test_AL_07_old_and_new_values_cast_as_arrays(): void
    {
        $log = $this->seedLog();

        $fresh = $log->fresh();
        $this->assertIsArray($fresh->old_values);
        $this->assertSame('old', $fresh->old_values['k']);
        $this->assertIsArray($fresh->new_values);
        $this->assertSame('new', $fresh->new_values['k']);
    }

    // ───────────── Service: getUserAuditLog ─────────────

    public function test_AL_08_get_user_audit_log_filters_by_user_id(): void
    {
        $other = User::query()->create([
            'name' => 'Other', 'email' => 'other@audit.test',
            'password' => Hash::make('password'),
            'role' => 'employee', 'is_active' => true,
        ]);

        $this->seedLog(['user_id' => $this->admin->id, 'action' => 'admin_action_1']);
        $this->seedLog(['user_id' => $this->admin->id, 'action' => 'admin_action_2']);
        $this->seedLog(['user_id' => $other->id, 'action' => 'other_action']);

        /** @var AuditService $svc */
        $svc = app(AuditService::class);
        $page = $svc->getUserAuditLog($this->admin->id, 50);

        $this->assertSame(2, $page->total());
        $this->assertSame('admin_action_1', $page->items()[0]->action);
    }

    public function test_AL_09_get_model_audit_log_filters_by_morph_target(): void
    {
        $this->seedLog(['model_type' => User::class, 'model_id' => 1, 'action' => 'a1']);
        $this->seedLog(['model_type' => User::class, 'model_id' => 2, 'action' => 'a2']);
        $this->seedLog(['model_type' => Account::class, 'model_id' => 1, 'action' => 'a3']);

        /** @var AuditService $svc */
        $svc = app(AuditService::class);
        $logs = $svc->getModelAuditLog(User::class, 1);

        $this->assertCount(1, $logs);
        $this->assertSame('a1', $logs->first()->action);
    }

    public function test_AL_10_get_daily_report_aggregates_for_date(): void
    {
        $today = now()->format('Y-m-d');
        $this->seedLog(['action' => 'create_approval_request']);
        $this->seedLog(['action' => 'approve_request']);
        $this->seedLog(['action' => 'reject_request']);

        /** @var AuditService $svc */
        $svc = app(AuditService::class);
        $report = $svc->getDailyReport(now());

        $this->assertArrayHasKey('total_actions', $report);
        $this->assertSame(3, $report['total_actions']);
        $this->assertArrayHasKey('date', $report);
        $this->assertSame($today, $report['date']);
    }
}
