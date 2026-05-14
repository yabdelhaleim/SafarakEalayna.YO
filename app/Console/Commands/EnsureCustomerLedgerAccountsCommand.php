<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Observers\CustomerLedgerObserver;
use Illuminate\Console\Command;

/**
 * Backfill customers.account_id for legacy rows (idempotent).
 */
class EnsureCustomerLedgerAccountsCommand extends Command
{
    protected $signature = 'accounting:ensure-customer-accounts {--chunk=200}';

    protected $description = 'Create ledger Account rows for customers missing account_id (safe incremental backfill).';

    public function handle(CustomerLedgerObserver $observer): int
    {
        $chunk = max(10, (int) $this->option('chunk'));
        $processed = 0;

        Customer::query()
            ->whereNull('account_id')
            ->orderBy('id')
            ->chunkById($chunk, function ($customers) use ($observer, &$processed): void {
                foreach ($customers as $customer) {
                    /** @var Customer $customer */
                    $observer->created($customer);
                    $processed++;
                }
            });

        $this->info(sprintf('Processed %d customer(s) missing account_id.', $processed));

        return self::SUCCESS;
    }
}
