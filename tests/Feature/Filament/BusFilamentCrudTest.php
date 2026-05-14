<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\BusBookings\Pages\ManageBusBookings;
use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use App\Filament\Admin\Resources\BusCompanies\Pages\ManageBusCompanies;
use App\Filament\Admin\Resources\BusInventories\Pages\ManageBusInventories;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * يحاكي CRUD من واجهة Filament (Livewire) لموارد الباص.
 */
class BusFilamentCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);
    }

    public function test_bus_companies_filament_index_returns_ok(): void
    {
        $this->get(BusCompanyResource::getUrl())->assertOk();
    }

    public function test_filament_can_create_bus_company_via_create_action(): void
    {
        Livewire::test(ManageBusCompanies::class)
            ->callAction('create', data: [
                'name' => 'شركة من Filament',
                'phone' => '01001230000',
                'is_active' => true,
            ])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bus_companies', [
            'name' => 'شركة من Filament',
            'phone' => '01001230000',
        ]);
    }

    public function test_filament_can_create_deferred_inventory_and_booking(): void
    {
        Livewire::test(ManageBusCompanies::class)
            ->callAction('create', data: [
                'name' => 'Co Filament Bus',
                'phone' => '01005556666',
                'is_active' => true,
            ])
            ->assertHasNoErrors();

        $companyId = BusCompany::query()->where('name', 'Co Filament Bus')->value('id');
        $this->assertNotNull($companyId);

        $travelDate = now()->addDays(10)->toDateString();

        Livewire::test(ManageBusInventories::class)
            ->callAction('create', data: [
                'company_id' => $companyId,
                'route' => 'Luxor - Aswan',
                'travel_date' => $travelDate,
                'departure_time' => '09:00',
                'total_tickets' => 15,
                'cost_per_ticket' => 20,
                'selling_price' => 55,
                'payment_type' => 'deferred',
                'notes' => 'رحلة من Filament',
            ])
            ->assertHasNoErrors();

        $inventoryId = BusInventory::query()->where('route', 'Luxor - Aswan')->value('id');
        $this->assertNotNull($inventoryId);

        Livewire::test(ManageBusBookings::class)
            ->callAction('create', data: [
                'inventory_id' => $inventoryId,
                'quantity' => 1,
                'customer_name' => 'عميل Filament',
                'customer_phone' => '01008887777',
            ])
            ->assertHasNoErrors();

        $this->assertTrue(
            BusBooking::query()->where('inventory_id', $inventoryId)->where('quantity', 1)->exists()
        );
    }
}
