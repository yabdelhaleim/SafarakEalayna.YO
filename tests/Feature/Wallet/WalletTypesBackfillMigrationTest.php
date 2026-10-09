<?php

namespace Tests\Feature\Wallet;

use App\Enums\WalletProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression test for the `2026_08_28_010000_backfill_canonical_wallet_types`
 * migration.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Why this test does NOT use `RefreshDatabase`
 * ─────────────────────────────────────────────────────────────────────────
 * The pre-existing migration `2026_08_28_000000_convert_online_service_type…`
 * uses MySQL-specific `UPDATE … JOIN` syntax that fails on the project's
 * SQLite test database. Rather than rewriting that migration (out of scope
 * here), we build the `wallet_types` schema manually with the `Schema`
 * facade so the test exercises only the migration under test, in isolation.
 *
 * Bug background (2026-08-28): the original seed migration was disabled on
 * 2026-07-29 by user request so `migrate:fresh` produces an EMPTY database.
 * As a result, environments that were not seeded manually ended up with
 * `wallet_types` missing the canonical codes that match the
 * `accounts.wallet_provider` enum values. The Vue UI then showed the warning
 * "يوجد N محفظة مسجلة فعلاً، لكن نوعها (…) لا يطابق النوع المختار" for
 * every selected wallet type, even when the accounts were perfectly valid.
 */
class WalletTypesBackfillMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // isolation: نبني الـ schema يدوياً عشان نتفادى migrations قديمة معطّلة على SQLite
        if (! Schema::hasTable('wallet_types')) {
            Schema::create('wallet_types', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wallet_types');
        parent::tearDown();
    }

    private function runMigration(): void
    {
        $migration = require __DIR__ . '/../../../database/migrations/2026_08_28_010000_backfill_canonical_wallet_types.php';
        $migration->up();
    }

    #[Test]
    public function backfill_inserts_all_canonical_wallet_type_rows_into_empty_table(): void
    {
        // Arrange: تأكيد إن الجدول فاضي قبل الـ migration
        $this->assertSame(0, DB::table('wallet_types')->count());

        // Act
        $this->runMigration();

        // Assert
        $rows = DB::table('wallet_types')->orderBy('sort_order')->get();
        $this->assertCount(10, $rows);

        $expectedCodes = collect(WalletProvider::cases())->map(fn ($c) => $c->value)->all();
        $actualCodes = $rows->pluck('code')->all();

        sort($expectedCodes);
        sort($actualCodes);
        $this->assertSame($expectedCodes, $actualCodes);

        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->is_active, "Row {$row->code} must be active");
            $this->assertNotNull($row->created_at);
            $this->assertNotNull($row->updated_at);
        }
    }

    #[Test]
    public function backfill_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $this->runMigration();
        $this->runMigration();
        $this->runMigration();

        $this->assertSame(10, DB::table('wallet_types')->count());
    }

    #[Test]
    public function backfill_preserves_admin_edited_name_and_sort_order(): void
    {
        $this->runMigration();

        // الأدمن عدّل الاسم والـ sort_order يدوياً وعطّل النوع
        DB::table('wallet_types')->where('code', 'vodafone_cash')->update([
            'name'       => 'فودافون كاش - محرّر',
            'sort_order' => 99,
            'is_active'  => false,
        ]);

        $this->runMigration();

        $row = DB::table('wallet_types')->where('code', 'vodafone_cash')->first();
        $this->assertSame('فودافون كاش - محرّر', $row->name);
        $this->assertSame(99, (int) $row->sort_order);
        $this->assertSame(0, (int) $row->is_active);
    }

    #[Test]
    public function backfill_matches_account_wallet_provider_values(): void
    {
        $this->runMigration();

        $walletTypeCodes = DB::table('wallet_types')->pluck('code')->all();

        foreach (WalletProvider::cases() as $provider) {
            $this->assertContains(
                $provider->value,
                $walletTypeCodes,
                "WalletProvider::{$provider->name} ({$provider->value}) must have a matching wallet_types row"
            );
        }
    }
}
