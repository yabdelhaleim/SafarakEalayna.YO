<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConsolidateDuplicateBookings extends Command
{
    protected $signature = 'bookings:consolidate {--dry-run : Only show what would be changed}';
    protected $description = 'Consolidate legacy and new bookings for Hajj, Umrah, and Visas';

    public function handle()
    {
        if (!Schema::hasTable('hajj_umrah') || !Schema::hasTable('visas')) {
            $this->error('Legacy tables not found. Consolidation already performed?');
            return;
        }

        $dryRun = $this->option('dry-run');

        $this->info($dryRun ? 'Running in DRY-RUN mode...' : 'Starting consolidation...');

        DB::transaction(function () use ($dryRun) {
            $this->consolidateHajjUmrah($dryRun);
            $this->consolidateVisas($dryRun);
        });

        $this->info('Consolidation complete.');
    }

    private function consolidateHajjUmrah($dryRun)
    {
        $this->comment('Analyzing Hajj/Umrah duplicates...');
        
        $duplicates = DB::table('hajj_umrah as legacy')
            ->join('customers as c', 'legacy.phone', '=', 'c.phone')
            ->join('hajj_umra_bookings as new', 'c.id', '=', 'new.customer_id')
            ->select('legacy.id as legacy_id', 'new.id as new_id', 'legacy.client_name')
            ->whereRaw('ABS(legacy.selling_price - new.selling_price) < 1.0')
            ->get();

        $this->info("Found {$duplicates->count()} high-confidence Hajj/Umrah duplicates.");

        foreach ($duplicates as $dup) {
            if (!$dryRun) {
                // 1. Update any treasury transactions pointing to legacy ID (if any)
                // Note: This depends on how legacy transactions were stored. 
                // In the new system, they point to flight_booking_id or similar.
                
                // 2. Soft delete legacy record
                DB::table('hajj_umrah')->where('id', $dup->legacy_id)->update(['deleted_at' => now()]);
            }
            $this->line("Consolidated legacy #{$dup->legacy_id} into new #{$dup->new_id} ({$dup->client_name})");
        }
    }

    private function consolidateVisas($dryRun)
    {
        $this->comment('Analyzing Visa duplicates...');
        
        $duplicates = DB::table('visas as legacy')
            ->join('customers as c', 'legacy.phone', '=', 'c.phone')
            ->join('visa_bookings as new', 'c.id', '=', 'new.customer_id')
            ->select('legacy.id as legacy_id', 'new.id as new_id', 'legacy.client_name')
            ->whereRaw('ABS(legacy.selling_price - new.selling_price) < 1.0')
            ->get();

        $this->info("Found {$duplicates->count()} high-confidence Visa duplicates.");

        foreach ($duplicates as $dup) {
            if (!$dryRun) {
                DB::table('visas')->where('id', $dup->legacy_id)->update(['deleted_at' => now()]);
            }
            $this->line("Consolidated legacy #{$dup->legacy_id} into new #{$dup->new_id} ({$dup->client_name})");
        }
    }
}
