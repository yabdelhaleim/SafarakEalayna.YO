<?php

declare(strict_types=1);

namespace Tests\Stress;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Stress\Support\StressSafetyGuard;

/**
 * Base class for every Phase 25 stress test under tests/Stress/.
 *
 * Responsibilities:
 *  - Hard-pre-flight via StressSafetyGuard before any DB work
 *  - SQLite WAL + busy_timeout (so concurrent writers don't fail on locks
 *    when multiple stress tests share the same :memory: process boundary)
 *  - Deterministic randomization: mt_srand(20260814) + Faker seed 20260814
 *  - Per-test snapshot store so reconciliation can diff before/after
 *  - Seeds ONE dedicated stress-test User (for `created_by` FKs) and
 *    exposes its id as $this->actorId.
 *
 * NOTE: PHPUnit tests run single-threaded per process, so true
 * lockForUpdate() semantics are NOT exercised here. The MySQL tier
 * (tests/scripts/stress_*.php) covers that.
 */
abstract class FinanceStressTestCase extends TestCase
{
    use RefreshDatabase;

    /** Spec-mandated fixed seed for reproducibility. */
    public const SEED = 20260814;

    /** Concurrency semantics of the SQLite tier (informational). */
    public const CONCURRENCY_SEMANTICS = 'single-writer (SQLite :memory: — true lockForUpdate tested separately on MySQL tier)';

    /** Per-test snapshot taken in setUp(); compared in tearDown() by helpers. */
    protected array $baselineSnapshot = [];

    /** The dedicated stress-test actor user id (created in setUp). */
    protected int $actorId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Hard pre-flight. Aborts the test (fail) before any DB write.
        StressSafetyGuard::assertSafeEnvironment('sqlite');

        // 2. SQLite hardening for the stress tier.
        //
        //    RefreshDatabase wraps every test in DB::transaction() — inside a
        //    transaction SQLite refuses PRAGMA `synchronous` / `journal_mode`
        //    changes ("Safety level may not be changed inside a transaction").
        //    So we ONLY apply WAL/busy_timeout/foreign_keys PRAGMAs:
        //      - when not currently inside a transaction (DB::transactionLevel() === 0)
        //      - when the SQLite file is file-backed (NOT :memory:, where WAL
        //        and journal_mode are no-ops anyway)
        //
        //    For the in-memory PHPUnit tier these PRAGMAs are skipped; the
        //    bulk / file-backed stress tier (storage/app/stress.sqlite) gets
        //    the full hardening.
        if (DB::connection()->getDriverName() === 'sqlite'
            && DB::transactionLevel() === 0
            && DB::connection()->getDatabaseName() !== ':memory:'
        ) {
            DB::statement('PRAGMA journal_mode = WAL');
            DB::statement('PRAGMA busy_timeout = 5000');
            DB::statement('PRAGMA foreign_keys = ON');
        }

        // 3. Deterministic randomization. mt_srand drives rand() everywhere;
        //    seed faker explicitly so any Faker inside factories is reproducible.
        mt_srand(self::SEED);
        $this->app->make(\Faker\Generator::class)->seed(self::SEED);

        // 4. Seed a dedicated stress-test user. Reuse it for every created_by
        //    so the FK constraint on suppliers.created_by / customers.created_by
        //    / accounts.created_by resolves. We do NOT modify UserFactory —
        //    we just create one user via the existing factory.
        $actor = User::factory()->create([
            'name'  => 'STRESS-ACTOR',
            'email' => 'stress-actor@safarakealayna.test',
            'role'  => 'admin',
        ]);
        $this->actorId = (int) $actor->id;

        // 5. Baseline snapshot (counts only, to keep memory low).
        $this->baselineSnapshot = $this->captureSnapshot();

        // 6. Print a one-line test banner so logs show what's running.
        fwrite(STDOUT, sprintf(
            "[stress] %s :: concurrency=%s :: actor=%d\n",
            static::class,
            self::CONCURRENCY_SEMANTICS,
            $this->actorId
        ));
    }

    /**
     * Take a structural snapshot: row counts of every finance-related table.
     * Plus financial totals. Cheap; safe to call before & after each test.
     */
    protected function captureSnapshot(): array
    {
        return [
            'counts' => [
                'accounts'           => DB::table('accounts')->count(),
                'account_entries'    => DB::table('account_entries')->count(),
                'transactions'       => DB::table('transactions')->count(),
                'transfers'          => DB::table('transfers')->count(),
                'customers'          => DB::table('customers')->count(),
                'suppliers'          => DB::table('suppliers')->count(),
                'bus_bookings'       => Schema::hasTable('bus_bookings') ? DB::table('bus_bookings')->count() : 0,
                'bus_payments'       => Schema::hasTable('bus_payments') ? DB::table('bus_payments')->count() : 0,
                'hajj_umra_bookings' => Schema::hasTable('hajj_umra_bookings') ? DB::table('hajj_umra_bookings')->count() : 0,
                'hajj_umra_payments' => Schema::hasTable('hajj_umra_payments') ? DB::table('hajj_umra_payments')->count() : 0,
                'visa_bookings'      => Schema::hasTable('visa_bookings') ? DB::table('visa_bookings')->count() : 0,
                'visa_payments'      => Schema::hasTable('visa_payments') ? DB::table('visa_payments')->count() : 0,
                'online_transactions'=> Schema::hasTable('online_transactions') ? DB::table('online_transactions')->count() : 0,
                'fawry_transactions' => Schema::hasTable('fawry_transactions') ? DB::table('fawry_transactions')->count() : 0,
                'wallet_transactions'=> Schema::hasTable('wallet_transactions') ? DB::table('wallet_transactions')->count() : 0,
            ],
            'totals' => [
                'account_balance_sum'    => (float) DB::table('accounts')->sum('balance'),
                'credits_sum'            => (float) DB::table('account_entries')->sum('credit'),
                'debits_sum'             => (float) DB::table('account_entries')->sum('debit'),
            ],
            'captured_at_unix' => time(),
        ];
    }

    /**
     * Compute the delta between baselineSnapshot and the current state.
     * @return array{counts: array<string,int>, totals: array<string,float>}
     */
    protected function snapshotDelta(): array
    {
        $now = $this->captureSnapshot();
        $deltas = ['counts' => [], 'totals' => []];
        foreach ($now['counts'] as $k => $v) {
            $deltas['counts'][$k] = $v - ($this->baselineSnapshot['counts'][$k] ?? 0);
        }
        foreach ($now['totals'] as $k => $v) {
            $deltas['totals'][$k] = round($v - ($this->baselineSnapshot['totals'][$k] ?? 0.0), 2);
        }
        return $deltas;
    }

    /**
     * Persist a per-test JSON artifact under storage/app/stress/ for the
     * final reconciliation report. Creates the directory if missing.
     */
    protected function writeArtifact(string $phase, string $name, array $payload): string
    {
        $dir = storage_path('app/stress');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir.'/'.$phase.'-'.$name.'.json';
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $file;
    }
}
