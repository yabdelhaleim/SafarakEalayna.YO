<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\SoftDeletes;

class VerifySoftDeletes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:verify-soft-deletes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify SoftDeletes trait and deleted_at column consistency across all models';

    /**
     * Models that MUST have SoftDeletes + deleted_at column.
     */
    protected $mustHaveSoftDeletes = [
        'App\Models\Customer',
        'App\Models\Flight\FlightBooking',
        'App\Models\Bus\BusCompany',
        'App\Models\Bus\BusInventory',
        'App\Models\Bus\BusBooking',
        'App\Models\Service\Service',
        'App\Models\Service\ServiceOrder',
        'App\Models\Online\OnlineServiceType',
    ];

    /**
     * Models that MUST NOT have SoftDeletes (permanent records).
     */
    protected $mustNotHaveSoftDeletes = [
        'App\Models\Transaction',
        'App\Models\AccountEntry',
        'App\Models\Transfer',
        'App\Models\Flight\FlightPayment',
        'App\Models\Flight\FlightRefund',
        'App\Models\Service\ServicePayment',
        'App\Models\Bus\BusCompanyPayment',
        'App\Models\Employee\EmployeeBonus',
        'App\Models\Online\OnlineTransaction',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('═══════════════════════════════════════');
        $this->info('SOFT DELETE VERIFICATION');
        $this->info('═══════════════════════════════════════');

        $hasIssues = false;

        // Check models that MUST have SoftDeletes
        $this->newLine();
        $this->info('Models that MUST have SoftDeletes:');
        foreach ($this->mustHaveSoftDeletes as $modelClass) {
            $result = $this->verifyModel($modelClass, true);
            if (!$result['valid']) {
                $hasIssues = true;
            }
        }

        // Check models that MUST NOT have SoftDeletes
        $this->newLine();
        $this->info('Models that MUST NOT have SoftDeletes:');
        foreach ($this->mustNotHaveSoftDeletes as $modelClass) {
            $result = $this->verifyModel($modelClass, false);
            if (!$result['valid']) {
                $hasIssues = true;
            }
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');

        if ($hasIssues) {
            $this->error('❌ VERIFICATION FAILED - Issues found above');
            return 1;
        }

        $this->info('✅ ALL CHECKS PASSED - System is consistent');
        return 0;
    }

    /**
     * Verify a single model for SoftDeletes consistency.
     */
    protected function verifyModel(string $modelClass, bool $shouldHaveSoftDeletes): array
    {
        $modelName = last(explode('\\', $modelClass));
        $table = $this->getTableName($modelClass);

        $usesSoftDeletes = $this->usesSoftDeletes($modelClass);
        $hasDeletedAtColumn = Schema::hasColumn($table, 'deleted_at');

        $output = "  ";
        $valid = true;

        if ($shouldHaveSoftDeletes) {
            if ($usesSoftDeletes && $hasDeletedAtColumn) {
                $output .= "✅ {$modelName} — SoftDeletes: YES | deleted_at column: YES";
            } else {
                $output .= "❌ {$modelName} — SoftDeletes: " . ($usesSoftDeletes ? 'YES' : 'NO');
                $output .= " | deleted_at column: " . ($hasDeletedAtColumn ? 'YES' : 'NO');
                $output .= " (should both be YES)";
                $valid = false;
            }
        } else {
            if (!$usesSoftDeletes) {
                $output .= "✅ {$modelName} — SoftDeletes: NO (correct)";
            } else {
                $output .= "❌ {$modelName} — SoftDeletes: YES (should be NO)";
                $valid = false;
            }
        }

        $this->info($output);

        return [
            'model' => $modelName,
            'uses_soft_deletes' => $usesSoftDeletes,
            'has_deleted_at' => $hasDeletedAtColumn,
            'valid' => $valid,
        ];
    }

    /**
     * Check if a model uses the SoftDeletes trait.
     */
    protected function usesSoftDeletes(string $modelClass): bool
    {
        if (!class_exists($modelClass)) {
            return false;
        }

        $traits = class_uses_recursive($modelClass);

        return in_array(SoftDeletes::class, $traits, true);
    }

    /**
     * Get the table name for a model.
     */
    protected function getTableName(string $modelClass): string
    {
        if (!class_exists($modelClass)) {
            return '';
        }

        $instance = new $modelClass;

        return $instance->getTable();
    }
}
