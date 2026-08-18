<?php

namespace Tests\Feature\TourismAudit;

use App\Support\Finance\AccountModuleContract;

/**
 * Section 2 / 3 — Environment Safety & Official Module Boundary.
 *
 * Verifies the audit is running against local MySQL (NOT production),
 * and asserts the canonical division contract.
 */
class EnvironmentSafetyTest extends TourismAuditTestCase
{
    public function test_app_env_is_local_or_testing(): void
    {
        $env = config('app.env');
        $this->assertContains($env, ['local', 'testing'], "APP_ENV='{$env}' — production audit ABORT");
    }

    public function test_db_connection_is_safe(): void
    {
        $default = config('database.default');
        $host = config("database.connections.{$default}.host");
        $database = config("database.connections.{$default}.database");

        // Per phpunit.xml, tests use sqlite :memory:. Per .env (when not testing), local MySQL on 127.0.0.1.
        if ($default === 'sqlite') {
            $this->assertSame(':memory:', $database, "PHPUnit should use sqlite :memory: for safety, got: {$database}");
        } else {
            $this->assertSame('127.0.0.1', $host, "MySQL host must be 127.0.0.1, got: {$host}");
        }
    }

    public function test_canonical_division_contract(): void
    {
        // Tourism division members
        $this->assertContains('flights', AccountModuleContract::TOURISM_DIVISION_MODULES);
        $this->assertContains('hajj_umra', AccountModuleContract::TOURISM_DIVISION_MODULES);
        $this->assertContains('visas', AccountModuleContract::TOURISM_DIVISION_MODULES);
        $this->assertContains('tourism', AccountModuleContract::TOURISM_DIVISION_MODULES);

        // Office division members
        $this->assertContains('bus', AccountModuleContract::OFFICE_DIVISION_MODULES);
        $this->assertContains('fawry', AccountModuleContract::OFFICE_DIVISION_MODULES);
        $this->assertContains('online', AccountModuleContract::OFFICE_DIVISION_MODULES);
        $this->assertContains('wallet_transfer', AccountModuleContract::OFFICE_DIVISION_MODULES);
        $this->assertContains('office', AccountModuleContract::OFFICE_DIVISION_MODULES);

        // divisionFor()
        $this->assertSame('tourism', AccountModuleContract::divisionFor('flights'));
        $this->assertSame('tourism', AccountModuleContract::divisionFor('hajj_umra'));
        $this->assertSame('tourism', AccountModuleContract::divisionFor('visas'));
        $this->assertSame('office', AccountModuleContract::divisionFor('bus'));
        $this->assertSame('office', AccountModuleContract::divisionFor('fawry'));
        $this->assertSame('office', AccountModuleContract::divisionFor('online'));
        $this->assertNull(AccountModuleContract::divisionFor('general'));

        // isTourismModule / isOfficeModule
        $this->assertTrue(AccountModuleContract::isTourismModule('flights'));
        $this->assertTrue(AccountModuleContract::isOfficeModule('bus'));
        $this->assertFalse(AccountModuleContract::isTourismModule('bus'));
    }

    public function test_balance_convention_documented(): void
    {
        // Project invariant: balance = SUM(credit) - SUM(debit)
        // After seed, the EGP vault has 1,000,000 with opening credit 1,000,000 and a no-op entry.
        $this->assertAccountBalance($this->vaultEgp, 1_000_000.0);
    }

    public function test_no_production_touched(): void
    {
        // If we're running through phpunit, DB_CONNECTION is sqlite per phpunit.xml.
        // If we're running artisan tinker, the audit script MUST verify local.
        // This test asserts that we never connected to a non-local MySQL host.
        $default = config('database.default');

        if ($default === 'mysql') {
            $host = config('database.connections.mysql.host');
            $this->assertContains($host, ['127.0.0.1', 'localhost'], "Production host detected: {$host}");
        }

        // Always pass — no production touch possible from PHPUnit.
        $this->assertTrue(true);
    }
}
