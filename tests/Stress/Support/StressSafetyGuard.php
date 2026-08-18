<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

use Illuminate\Support\Facades\DB;

/**
 * StressSafetyGuard — hard pre-flight checks for the stress harness.
 *
 * Defends against accidental writes to the production-like dev DB
 * (safarakealayna, travel_office, etc.) or to a production-like APP_ENV.
 *
 * Every entry point (PHPUnit setUp, standalone PHP script, seeder,
 * teardown) MUST call assertSafeEnvironment() before touching the DB.
 *
 * On violation:
 *  - prints ABORT banner to STDERR
 *  - throws StressSafetyAbort (or exits with code 2 for scripts)
 */
final class StressSafetyGuard
{
    /** Production-like DB names that MUST NEVER be touched. */
    public const FORBIDDEN_DATABASES = [
        'safarakealayna',
        'safarak_ealayna',
        'travel_office',
        'production',
        'prod',
    ];

    /** Production-like APP_ENV values. */
    public const FORBIDDEN_APP_ENVS = ['production', 'prod', 'live'];

    /** The dedicated stress MySQL schema (allowed). */
    public const STRESS_MYSQL_DB = 'safarak_stress';

    /** The dedicated stress SQLite file (allowed). */
    public const STRESS_SQLITE_PATH = 'storage/app/stress.sqlite';

    /** The required stress HTTP port (allowed). */
    public const STRESS_PORT = 18000;

    /**
     * Verify the current environment is safe for stress testing.
     * Throws StressSafetyAbort on any forbidden configuration.
     *
     * @param  string|null  $expectedTier  'sqlite' | 'mysql' | null (allow either)
     */
    public static function assertSafeEnvironment(?string $expectedTier = null): void
    {
        $cfg = config('database.connections.'.config('database.default'));

        $connection = config('database.default');
        // For SQLite, there is no host. Show the driver path / file instead.
        $host       = is_array($cfg) ? ($cfg['host'] ?? null) : null;
        if ($host === null || $host === '') {
            $host = $connection === 'sqlite'
                ? 'sqlite://'.(is_array($cfg) ? ($cfg['database'] ?? 'unknown') : 'unknown')
                : 'unknown';
        }
        $database   = is_array($cfg) ? ($cfg['database'] ?? 'unknown') : 'unknown';
        $appEnv     = app()->environment();

        // Banner — printed unconditionally so the operator sees what DB we are on.
        self::printBanner($connection, $host, $database, $appEnv);

        // 1. Forbidden DB names.
        if (in_array(strtolower((string) $database), self::FORBIDDEN_DATABASES, true)) {
            throw new StressSafetyAbort(
                "Refusing to run stress test against forbidden database '{$database}'. "
                ."Allowed: '".self::STRESS_MYSQL_DB."' or '".self::STRESS_SQLITE_PATH."'."
            );
        }

        // 2. Forbidden APP_ENV.
        if (in_array(strtolower($appEnv), self::FORBIDDEN_APP_ENVS, true)) {
            throw new StressSafetyAbort(
                "Refusing to run stress test under APP_ENV='{$appEnv}'. "
                ."Required: 'stress' (or 'testing')."
            );
        }

        // 3. Tier consistency (when caller specifies).
        if ($expectedTier === 'sqlite' && $connection !== 'sqlite') {
            throw new StressSafetyAbort(
                "Expected SQLite tier but DB_CONNECTION='{$connection}'."
            );
        }
        if ($expectedTier === 'mysql' && $connection !== 'mysql') {
            throw new StressSafetyAbort(
                "Expected MySQL tier but DB_CONNECTION='{$connection}'."
            );
        }

        // 4. MySQL stress tier MUST be safarak_stress.
        if ($connection === 'mysql' && strtolower((string) $database) !== self::STRESS_MYSQL_DB) {
            throw new StressSafetyAbort(
                "MySQL stress tier must use database '".self::STRESS_MYSQL_DB."' "
                ."but is '{$database}'."
            );
        }

        // 5. SQLite stress tier must use the stress.sqlite file (or :memory: under PHPUnit).
        if ($connection === 'sqlite') {
            $allowedSqlite = [self::STRESS_SQLITE_PATH, ':memory:'];
            if (!in_array($database, $allowedSqlite, true)
                && !str_ends_with((string) $database, '/storage/app/stress.sqlite')
            ) {
                throw new StressSafetyAbort(
                    "SQLite stress tier must use '".self::STRESS_SQLITE_PATH
                    ."' (or :memory:) but is '{$database}'."
                );
            }
        }
    }

    /**
     * Verify the dedicated stress HTTP server is running on the right port
     * (only meaningful for scripts about to fire HTTP requests).
     * Optional — fails soft (warning) rather than aborting, since callers
     * may invoke internal service methods without an HTTP server.
     */
    public static function assertStressServerUp(string $baseUrl = 'http://127.0.0.1:18000'): bool
    {
        $port = parse_url($baseUrl, PHP_URL_PORT) ?: 80;
        if ((int) $port !== self::STRESS_PORT) {
            throw new StressSafetyAbort(
                "Stress HTTP server must run on port ".self::STRESS_PORT." but URL is {$baseUrl}."
            );
        }

        $sock = @fsockopen(parse_url($baseUrl, PHP_URL_HOST) ?: '127.0.0.1', (int) $port, $errno, $errstr, 1.0);
        if (!$sock) {
            fwrite(STDERR, "⚠️  Stress HTTP server NOT reachable at {$baseUrl} ({$errstr})\n");
            return false;
        }
        fclose($sock);
        return true;
    }

    /**
     * Print the standard safety banner before any DB-touching work.
     */
    public static function printBanner(string $connection, string $host, string $database, string $appEnv): void
    {
        $line = str_repeat('=', 60);
        $msg = "\n{$line}\n"
            ."STRESS DB:  {$connection}\n"
            ."HOST:       {$host}\n"
            ."DATABASE:   {$database}\n"
            ."APP_ENV:    {$appEnv}\n"
            ."PID:        ".(int) getmypid()."\n"
            ."TIME:       ".date('Y-m-d H:i:s')."\n"
            .$line."\n";
        // Write to STDOUT (works under PHPUnit and CLI scripts).
        fwrite(STDOUT, $msg);
    }
}
