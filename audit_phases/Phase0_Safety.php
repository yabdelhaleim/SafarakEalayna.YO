<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

/**
 * PHASE 0 — Environment safety gate.
 *
 * Aborts the entire audit if any guard fails:
 *   1. APP_ENV must be `local`
 *   2. DB_CONNECTION must be `mysql` + DB_DATABASE must be `safarakealayna`
 *   3. The audit prefix is recorded
 *   4. Baseline counts captured so cleanup can detect audit-created rows later
 *   5. Required services + classes are autoloadable
 */
class Phase0_Safety
{
    public string $phaseLabel = 'PHASE 0 — Safety';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 0 — Safety');
        $r->start();

        try {
            // 1. APP_ENV must be local
            $env = Config::get('app.env');
            if ($env !== 'local') {
                $r->fatalError = "APP_ENV must be 'local', got '{$env}'";
                $r->finish();
                return $r;
            }
            $r->recordPass();

            // 2. DB_CONNECTION + DB_DATABASE
            $conn = Config::get('database.default');
            $db   = Config::get('database.connections.mysql.database');
            if ($conn !== 'mysql' || $db !== 'safarakealayna') {
                $r->fatalError = "DB must be mysql+safarakealayna, got {$conn}+{$db}";
                $r->finish();
                return $r;
            }
            $r->recordPass();

            // 3. Connectivity probe
            try {
                $one = DB::selectOne('SELECT 1 AS v');
                if ((int) $one->v !== 1) {
                    $r->fatalError = "DB connectivity probe failed";
                    $r->finish();
                    return $r;
                }
            } catch (\Throwable $e) {
                $r->fatalError = "DB connectivity error: " . $e->getMessage();
                $r->finish();
                return $r;
            }
            $r->recordPass();

            // 4. Required classes / services autoloadable
            $required = [
                \App\Services\Flight\FlightBookingService::class,
                \App\Services\Flight\RefundService::class,
                \App\Services\Flight\AviationService::class,
                \App\Services\HajjUmra\HajjUmraBookingService::class,
                \App\Services\HajjUmra\HajjUmraRefundService::class,
                \App\Services\Visa\VisaBookingService::class,
                \App\Services\Visa\VisaRefundService::class,
                \App\Services\Finance\TransactionService::class,
                \App\Services\Finance\RefundAuditLogger::class,
                \App\Models\Flight\FlightBooking::class,
                \App\Models\HajjUmraBooking::class,
                \App\Models\VisaBooking::class,
                \App\Models\RefundAuditLog::class,
            ];
            foreach ($required as $cls) {
                if (!class_exists($cls)) {
                    $r->fatalError = "Required class missing: {$cls}";
                    $r->finish();
                    return $r;
                }
            }
            $r->recordPass();

            // 5. Required tables exist (lightweight check)
            $requiredTables = [
                'accounts', 'account_entries', 'transactions',
                'flight_bookings', 'flight_payments',
                'hajj_umra_bookings', 'hajj_umra_payments',
                'visa_bookings', 'visa_payments',
                'customers', 'employees', 'users',
                'refund_requests', 'refund_audit_logs', 'audit_logs',
            ];
            $missing = [];
            foreach ($requiredTables as $t) {
                try {
                    DB::selectOne("SELECT 1 FROM {$t} LIMIT 1");
                } catch (\Throwable $e) {
                    $missing[] = $t;
                }
            }
            if (!empty($missing)) {
                $r->fatalError = "Missing tables: " . implode(',', $missing);
                $r->finish();
                return $r;
            }
            $r->recordPass();

            // 6. Baseline counts (snapshot for cleanup verification)
            $baseline = [
                'customers'        => DB::table('customers')->count(),
                'flight_bookings'  => DB::table('flight_bookings')->count(),
                'hajj_umra_bookings' => DB::table('hajj_umra_bookings')->count(),
                'visa_bookings'    => DB::table('visa_bookings')->count(),
                'transactions'     => DB::table('transactions')->count(),
                'accounts'         => DB::table('accounts')->count(),
                'users'            => DB::table('users')->count(),
                'employees'        => DB::table('employees')->count(),
            ];
            $r->recordInfo('Baseline counts', json_encode($baseline));

            // 7. Verify audit prefix can be set
            Config::set('audit.prefix', $this->ctx->prefix);
            $r->recordPass();

            // 8. Prerequisite audit — tourism vault presence
            //
            // The Flight/Hajj/Visa services all call
            // `Account::getModuleVault('<module>')` and throw a RuntimeException
            // if the vault is missing. Per `AccountModuleContract`, the
            // canonical tourism vault must have
            //   type='cashbox', module_type='tourism', is_module_vault=true,
            //   is_active=true
            // The audit MUST NOT modify the DB to create such an account;
            // it must simply report whether one already exists.
            $tourismVaultCount = DB::table('accounts')
                ->where('type', 'cashbox')
                ->where('module_type', 'tourism')
                ->where('is_module_vault', true)
                ->where('is_active', true)
                ->count();
            if ($tourismVaultCount === 0) {
                $r->recordFail(
                    scenario: 'Prerequisite: tourism vault account (module_type=tourism, is_module_vault=1)',
                    expected: 'At least 1 active tourism cashbox vault per AccountModuleContract',
                    actual: '0 accounts match — no tourism cashbox vault exists in DB',
                    severity: 'critical',
                    context: [
                        'module' => 'cross',
                        'root_cause' => 'AccountModuleContract mandates `module_type="tourism"` for tourism cashbox vaults; Account::getModuleVault() returns null for flights/hajj_umra/visas. ALL payment flows that omit `account_id` will throw RuntimeException.',
                    ],
                );
            } else {
                $r->recordPass();
            }

            // 9. Prerequisite audit — Employee model-DB email mismatch
            $hasEmployeeEmail = DB::selectOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'employees'
                   AND column_name = 'email'"
            );
            if ((int) $hasEmployeeEmail->cnt === 0) {
                $r->recordFail(
                    scenario: 'Prerequisite: employees.email column',
                    expected: 'employees.email exists (model declares it in #[Fillable])',
                    actual: 'employees.email column MISSING — Employee::firstOrCreate([email=>...]) would fail',
                    severity: 'medium',
                    context: [
                        'module' => 'cross',
                        'root_cause' => 'Employee model declares `email` in its Fillable attribute (Employee.php L23) but the local MySQL employees table does not have an `email` column. Audit cannot create Employees safely.',
                    ],
                );
            } else {
                $r->recordPass();
            }

            // 10. Prerequisite audit — users.employee_id column
            $hasUserEmployeeId = DB::selectOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'users'
                   AND column_name = 'employee_id'"
            );
            if ((int) $hasUserEmployeeId->cnt === 0) {
                $r->recordFail(
                    scenario: 'Prerequisite: users.employee_id column',
                    expected: 'users.employee_id exists (or no expectation of User↔Employee link)',
                    actual: 'users.employee_id column MISSING — User cannot link to Employee',
                    severity: 'medium',
                    context: [
                        'module' => 'cross',
                        'root_cause' => 'User model declares `employee_id` not in fillable, but the Employee model has user_id (FK) — relationship is Employee→User not User→Employee.',
                    ],
                );
            } else {
                $r->recordPass();
            }

            // 11. Prerequisite audit — audit_logs columns
            $hasAuditActorName = DB::selectOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'audit_logs'
                   AND column_name = 'actor_name'"
            );
            $hasAuditDescription = DB::selectOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'audit_logs'
                   AND column_name = 'description'"
            );
            $missingCols = [];
            if ((int) $hasAuditActorName->cnt === 0) $missingCols[] = 'actor_name';
            if ((int) $hasAuditDescription->cnt === 0) $missingCols[] = 'description';
            if (!empty($missingCols)) {
                $r->recordFail(
                    scenario: 'Prerequisite: audit_logs expected columns',
                    expected: 'audit_logs.actor_name + audit_logs.description exist',
                    actual: 'Missing columns: ' . implode(', ', $missingCols),
                    severity: 'medium',
                    context: [
                        'module' => 'cross',
                        'root_cause' => 'Local audit_logs schema lacks actor_name/description. Cleanup and queries that depend on them fail.',
                    ],
                );
            } else {
                $r->recordPass();
            }

        } catch (\Throwable $e) {
            $r->fatalError = 'Safety exception: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }
}
