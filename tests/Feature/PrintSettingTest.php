<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrintSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_print_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'employee']));

        $response = $this->getJson('/api/v1/settings/print');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'company_name_ar',
                    'company_name_en',
                    'modules',
                    'module_options',
                ],
            ]);

        $this->assertDatabaseHas('print_settings', ['id' => 1]);
    }

    public function test_admin_can_update_print_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->putJson('/api/v1/settings/print', [
            'company_name_ar' => 'سفرك علينا',
            'company_name_en' => 'Safarak Ealayna',
            'address' => 'القاهرة - مصر',
            'phones' => "01234567890\n01112223344",
            'finance_label' => 'المالية والمحاسب',
            'show_amount_due' => true,
            'modules' => [
                'flight' => ['ticket' => true, 'invoice' => false],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.company_name_en', 'Safarak Ealayna')
            ->assertJsonPath('data.modules.flight.ticket', true)
            ->assertJsonPath('data.modules.flight.invoice', false);

        $this->assertDatabaseHas('print_settings', [
            'id' => 1,
            'company_name_en' => 'Safarak Ealayna',
            'address' => 'القاهرة - مصر',
        ]);
    }

    public function test_non_admin_cannot_update_print_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'employee']));

        $this->putJson('/api/v1/settings/print', [
            'company_name_ar' => 'test',
        ])->assertForbidden();
    }
}
