<?php

namespace Tests\Feature\HajjUmra;

use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use Illuminate\Support\Facades\Event;

/**
 * PHASE 11 — Observer registration verification.
 *
 * Background (audit false positive, pre-fix):
 *   An initial audit pass reported that `UmrahSupplierObserver` was defined
 *   in `app/Observers/UmrahSupplierObserver.php` but never registered with
 *   Eloquent, so its auto-create-account behavior would never fire.
 *   Re-verification against the current `AppServiceProvider::boot()` showed
 *   the observer IS registered on line 97 (`UmrahSupplier::observe(...)`).
 *
 *   This test locks the registration in place. If anyone removes the
 *   registration line, the test will fail with a clear message — and the
 *   economic impact (no auto-created supplier AP account) is also visible
 *   as a side-effect assertion.
 */
class HajjUmraObserverRegistrationTest extends HajjUmraTestCase
{
    public function test_umrah_supplier_observer_is_registered(): void
    {
        // Inspect AppServiceProvider source for the registration line.
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertNotFalse($provider, 'AppServiceProvider.php must be readable');

        $this->assertStringContainsString(
            'UmrahSupplier::observe(UmrahSupplierObserver::class)',
            $provider,
            'UmrahSupplierObserver must be registered in AppServiceProvider::boot()'
        );
    }

    public function test_hajj_umra_executing_company_observer_is_registered(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertNotFalse($provider);

        $this->assertStringContainsString(
            'HajjUmraExecutingCompany::observe(HajjUmraExecutingCompanyObserver::class)',
            $provider,
            'HajjUmraExecutingCompanyObserver must be registered in AppServiceProvider::boot()'
        );
    }

    public function test_umrah_supplier_save_auto_creates_supplier_account(): void
    {
        // Behavioural check: the observer actually fires on save and creates
        // an Account row. Without registration, the resulting supplier would
        // have `account_id = null`.
        $supplier = UmrahSupplier::query()->create([
            'name' => 'مرصد مورد جديد',
            'phone' => '+966555000999',
            'default_cost_price' => 1200.0,
            'is_active' => true,
        ]);

        $this->assertNotNull($supplier->account_id,
            'UmrahSupplierObserver must auto-create the AP Account on first save');
        $this->assertDatabaseHas('accounts', [
            'id' => $supplier->account_id,
            'type' => 'supplier',
            'currency' => 'EGP',
            'module_type' => 'hajj_umra',
        ]);
    }

    public function test_hajj_umra_executing_company_save_auto_creates_supplier_account(): void
    {
        // The executing company observer creates a Supplier-type account.
        // (Note: HajjUmraExecutingCompany::booted() also has its own inline
        // saving hook that does the same thing — the observer registration
        // is a redundant safety net. The behavioural assertion verifies the
        // end-state, not which hook fires.)
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة تنفيذ جديدة',
            'license_number' => 'TEST-OBS-' . uniqid(),
            'phone' => '+201111111111',
            'is_active' => true,
        ]);

        $this->assertNotNull($company->account_id,
            'HajjUmraExecutingCompany must auto-create the AP Account on first save');
        $this->assertDatabaseHas('accounts', [
            'id' => $company->account_id,
            'type' => 'supplier',
            'currency' => 'EGP',
            'module_type' => 'hajj_umra',
        ]);
    }
}
