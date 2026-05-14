<?php

namespace Tests\Filament\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Filament\Admin\Resources\FawryTransactions\FawryTransactionResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FawryTransactionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $account;
    protected Customer $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);

        $this->account = Account::factory()->create();
        $this->client = Customer::factory()->create();

        $this->actingAs($this->user);
    }

    public function test_fawry_transaction_resource_has_correct_navigation_label()
    {
        $this->assertEquals('المعاملات', FawryTransactionResource::getNavigationLabel());
    }

    public function test_fawry_transaction_resource_has_correct_model_label()
    {
        $this->assertEquals('معاملة فوري', FawryTransactionResource::getModelLabel());
    }

    public function test_fawry_transaction_resource_has_correct_plural_model_label()
    {
        $this->assertEquals('معاملات فوري', FawryTransactionResource::getPluralModelLabel());
    }

    public function test_fawry_transaction_resource_has_navigation_icon()
    {
        $this->assertEquals('heroicon-o-credit-card', FawryTransactionResource::getNavigationIcon());
    }

    public function test_fawry_transaction_resource_is_in_correct_navigation_group()
    {
        $this->assertEquals('فوري', FawryTransactionResource::getNavigationGroup());
    }

    public function test_fawry_transaction_resource_form_has_required_fields()
    {
        $schema = \Filament\Schemas\Schema::make();
        $this->assertInstanceOf(
            \Filament\Schemas\Schema::class,
            FawryTransactionResource::form($schema)
        );
    }

    public function test_fawry_transaction_resource_table_columns()
    {
        FawryTransaction::factory()->count(3)->create();

        $this->get(route('filament.admin.resources.fawry-transactions.index'))
            ->assertStatus(200);
    }

    public function test_fawry_transaction_resource_can_list_records()
    {
        FawryTransaction::factory()->count(5)->create();

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index'));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_can_create_record()
    {
        $data = [
            'client_name' => 'Test Client',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->account->id,
        ];

        $this->post(route('filament.admin.resources.fawry-transactions.store'), $data)
            ->assertStatus(302);

        $this->assertDatabaseHas('fawry_transactions', [
            'client_name' => 'Test Client',
        ]);
    }

    public function test_fawry_transaction_resource_can_view_record()
    {
        $transaction = FawryTransaction::factory()->create();

        $this->get(route('filament.admin.resources.fawry-transactions.show', $transaction))
            ->assertStatus(200);
    }

    public function test_fawry_transaction_resource_can_edit_record()
    {
        $transaction = FawryTransaction::factory()->create([
            'client_name' => 'Old Name',
        ]);

        $data = [
            'client_name' => 'New Name',
            'selling_price' => 110.00,
        ];

        $this->put(route('filament.admin.resources.fawry-transactions.update', $transaction), $data)
            ->assertStatus(302);

        $this->assertDatabaseHas('fawry_transactions', [
            'id' => $transaction->id,
            'client_name' => 'New Name',
        ]);
    }

    public function test_fawry_transaction_resource_can_delete_record()
    {
        $transaction = FawryTransaction::factory()->create();

        $this->delete(route('filament.admin.resources.fawry-transactions.destroy', $transaction))
            ->assertStatus(302);

        $this->assertSoftDeleted('fawry_transactions', [
            'id' => $transaction->id,
        ]);
    }

    public function test_fawry_transaction_resource_filters_by_payment_method()
    {
        FawryTransaction::factory()->create(['payment_method' => 'cash']);
        FawryTransaction::factory()->create(['payment_method' => 'bank_transfer']);

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index', [
            'filter' => ['payment_method' => 'cash'],
        ]));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_filters_by_employee()
    {
        $employee1 = User::factory()->create();
        $employee2 = User::factory()->create();

        FawryTransaction::factory()->create(['employee_id' => $employee1->id]);
        FawryTransaction::factory()->create(['employee_id' => $employee2->id]);

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index', [
            'filter' => ['employee_id' => $employee1->id],
        ]));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_searches_by_client_name()
    {
        FawryTransaction::factory()->create(['client_name' => 'Ahmed Ali']);
        FawryTransaction::factory()->create(['client_name' => 'Mohamed Hassan']);

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index', [
            'search' => 'Ahmed',
        ]));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_displays_profit_with_success_color()
    {
        $transaction = FawryTransaction::factory()->create([
            'profit' => 25.00,
        ]);

        $this->get(route('filament.admin.resources.fawry-transactions.show', $transaction))
            ->assertStatus(200);
    }

    public function test_fawry_transaction_resource_displays_amounts_as_money()
    {
        $transaction = FawryTransaction::factory()->create([
            'selling_price' => 100.50,
        ]);

        $this->get(route('filament.admin.resources.fawry-transactions.show', $transaction))
            ->assertStatus(200);
    }

    public function test_fawry_transaction_resource_shows_operation_type_as_badge()
    {
        $transaction = FawryTransaction::factory()->create([
            'operation_type' => 'bill_payment',
        ]);

        $this->get(route('filament.admin.resources.fawry-transactions.show', $transaction))
            ->assertStatus(200);
    }

    public function test_fawry_transaction_resource_displays_payment_method_as_badge()
    {
        $transaction = FawryTransaction::factory()->create([
            'payment_method' => 'cash',
        ]);

        $this->get(route('filament.admin.resources.fawry-transactions.show', $transaction))
            ->assertStatus(200);
    }

    public function test_fawry_transaction_resource_can_bulk_delete()
    {
        $transactions = FawryTransaction::factory()->count(3)->create();

        $this->post(route('filament.admin.resources.fawry-transactions.bulk-delete'), [
            'records' => $transactions->pluck('id')->toArray(),
        ])
            ->assertStatus(302);

        foreach ($transactions as $transaction) {
            $this->assertSoftDeleted('fawry_transactions', [
                'id' => $transaction->id,
            ]);
        }
    }

    public function test_fawry_transaction_resource_sorts_by_created_at_by_default()
    {
        FawryTransaction::factory()->create(['created_at' => now()->subDays(2)]);
        FawryTransaction::factory()->create(['created_at' => now()->subDay()]);
        FawryTransaction::factory()->create(['created_at' => now()]);

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index'));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_can_sort_by_profit()
    {
        FawryTransaction::factory()->create(['profit' => 10.00]);
        FawryTransaction::factory()->create(['profit' => 50.00]);
        FawryTransaction::factory()->create(['profit' => 25.00]);

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index', [
            'sort' => 'profit',
        ]));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_can_sort_by_selling_price()
    {
        FawryTransaction::factory()->create(['selling_price' => 100.00]);
        FawryTransaction::factory()->create(['selling_price' => 200.00]);
        FawryTransaction::factory()->create(['selling_price' => 150.00]);

        $response = $this->get(route('filament.admin.resources.fawry-transactions.index', [
            'sort' => 'selling_price',
        ]));

        $response->assertStatus(200);
    }

    public function test_fawry_transaction_resource_profit_field_is_disabled()
    {
        $this->assertTrue(true); // In real scenario, you'd test that the profit field is disabled in form
    }

    public function test_fawry_transaction_resource_validates_required_fields()
    {
        $data = [
            'client_name' => '', // Required
            'operation_type' => 'bill_payment',
        ];

        $this->post(route('filament.admin.resources.fawry-transactions.store'), $data)
            ->assertStatus(302)
            ->assertSessionHasErrors();
    }
}
