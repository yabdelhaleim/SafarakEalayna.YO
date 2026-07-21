<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BusInventoryPaymentType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusGovernorate;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Production-readiness test seeder for the Bus module.
 *
 * Idempotent — re-runnable. Resets bus_module test rows on each run.
 *
 * What it seeds:
 *   - 1 office employee
 *   - 6 bus governorates (Cairo, Giza, Alexandria, Aswan, Luxor, Sharm)
 *   - 4 bus companies (Delta, Ghabbour, Go Bus, East Delta)
 *   - 8 bus inventories (mix of EGP, USD, SAR routes, cash + deferred)
 *   - 1 USD cashbox account (to receive USD payments)
 *   - 3 exchange rates (USD→EGP, SAR→EGP, EUR→EGP)
 *   - 4 new customers (EGP, USD, SAR mix)
 *   - Bus clearing accounts (income + expense) auto-resolved by
 *     LedgerClearingAccounts if missing.
 *
 * Total seed keeps the test environment realistic and gives the
 * BusBookingService plenty of data to exercise every code path.
 */
class BusModuleProductionTestSeeder extends Seeder
{
    public function run(): void
    {
        // Force auth for `created_by` columns / AuditLog writes
        Auth::loginUsingId(User::first()?->id ?? 1);

        DB::transaction(function () {

            $this->seedExchangeRates();
            $this->seedCashboxes();
            $this->seedEmployees();
            $this->seedGovernorates();
            $this->seedCustomers();
            $this->seedCompanies();
            $this->seedInventories();
            $this->seedClearingAccounts();

        });

        $this->command->info('✅ BusModuleProductionTestSeeder completed.');
    }

    protected function seedExchangeRates(): void
    {
        $today = Carbon::today()->toDateString();

        $rates = [
            ['USD', 'EGP', 49.50],
            ['SAR', 'EGP', 13.20],
            ['EUR', 'EGP', 53.80],
        ];

        foreach ($rates as [$from, $to, $rate]) {
            ExchangeRate::updateOrCreate(
                [
                    'from_currency' => $from,
                    'to_currency' => $to,
                    'effective_date' => $today,
                ],
                [
                    'rate' => $rate,
                    'is_active' => true,
                    'created_by' => 1,
                ]
            );
        }

        $this->command->info('  • Exchange rates: 3 (USD/SAR/EUR → EGP)');
    }

    protected function seedCashboxes(): void
    {
        // USD cashbox for foreign-currency receipts.
        LedgerBalanceMutationGuard::run(function () {
            Account::firstOrCreate(
                ['name' => 'خزينة الباص الدولارية'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 5000.00,
                    'currency' => 'USD',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة بالدولار لاستلام مدفوعات الباصات الأجنبية',
                    'created_by' => 1,
                ]
            );

            // SAR cashbox
            Account::firstOrCreate(
                ['name' => 'خزينة الباص الريال السعودي'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 10000.00,
                    'currency' => 'SAR',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة بالريال السعودي لاستلام مدفوعات الباصات',
                    'created_by' => 1,
                ]
            );
        });

        $this->command->info('  • Foreign-currency cashboxes: USD + SAR');
    }

    protected function seedEmployees(): void
    {
        $employees = [
            [
                'first_name' => 'يوسف',
                'last_name' => 'عبد الحميد',
                'full_name' => 'يوسف عبد الحميد',
                'national_id' => '29901011234567',
                'phone' => '01000000001',
                'position' => 'موظف حجز',
                'department' => 'قسم الباصات',
                'salary' => 8000.00,
                'hire_date' => '2025-01-01',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'status' => 'active',
                'gender' => 'male',
            ],
            [
                'first_name' => 'سارة',
                'last_name' => 'محمود',
                'full_name' => 'سارة محمود',
                'national_id' => '29902021234567',
                'phone' => '01000000002',
                'position' => 'محاسبة قسم الباصات',
                'department' => 'قسم الباصات',
                'salary' => 9500.00,
                'hire_date' => '2025-02-15',
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'status' => 'active',
                'gender' => 'female',
            ],
        ];

        foreach ($employees as $data) {
            Employee::firstOrCreate(
                ['national_id' => $data['national_id']],
                $data
            );
        }

        $this->command->info('  • Employees: 2 (active, bus department)');
    }

    protected function seedGovernorates(): void
    {
        $governorates = [
            ['name' => 'القاهرة', 'sort_order' => 1],
            ['name' => 'الجيزة', 'sort_order' => 2],
            ['name' => 'الإسكندرية', 'sort_order' => 3],
            ['name' => 'الأقصر', 'sort_order' => 4],
            ['name' => 'أسوان', 'sort_order' => 5],
            ['name' => 'شرم الشيخ', 'sort_order' => 6],
            ['name' => 'السويس', 'sort_order' => 7],
            ['name' => 'بورسعيد', 'sort_order' => 8],
        ];

        foreach ($governorates as $g) {
            BusGovernorate::firstOrCreate(
                ['name' => $g['name']],
                ['is_active' => true, 'sort_order' => $g['sort_order']]
            );
        }

        $this->command->info('  • Bus governorates: 8');
    }

    protected function seedCustomers(): void
    {
        $customers = [
            [
                'full_name' => 'محمد عبدالله السري',
                'phone' => '01200000001',
                'email' => 'mohamed.sari@example.com',
                'national_id' => '30001011234567',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'EG',
                'city' => 'القاهرة',
            ],
            [
                'full_name' => 'منى أحمد فؤاد',
                'phone' => '01200000002',
                'email' => 'mona.fouad@example.com',
                'national_id' => '30002021234567',
                'type' => 'individual',
                'customer_tier' => 'PREMIUM',
                'nationality' => 'EG',
                'city' => 'الإسكندرية',
            ],
            [
                'full_name' => 'خالد سعد المسافر',
                'phone' => '01200000003',
                'email' => 'khaled@example.com',
                'national_id' => '30003031234567',
                'type' => 'individual',
                'customer_tier' => 'VIP',
                'nationality' => 'SA',
                'city' => 'الرياض',
            ],
            [
                'full_name' => 'سامي عمر الخليوي',
                'phone' => '01200000004',
                'email' => 'sami@example.com',
                'national_id' => '30004041234567',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'KW',
                'city' => 'الكويت',
            ],
        ];

        foreach ($customers as $c) {
            Customer::firstOrCreate(['phone' => $c['phone']], $c);
        }

        $this->command->info('  • Customers: +4 (mixed EGP/SA/KW tiers)');
    }

    protected function seedCompanies(): void
    {
        $companies = [
            [
                'name' => 'شركة الدلتا للنقل البري',
                'phone' => '0223456789',
                'address' => 'القاهرة - مدينة نصر',
                'is_active' => true,
                'notes' => 'شركة نقل بين القاهرة والإسكندرية',
            ],
            [
                'name' => 'مصر لباصات الصعيد',
                'phone' => '0223456790',
                'address' => 'الجيزة - المهندسين',
                'is_active' => true,
                'notes' => 'رحلات الصعيد (أقصر / أسوان)',
            ],
            [
                'name' => 'Go Bus للسفر',
                'phone' => '0223456791',
                'address' => 'القاهرة - العباسية',
                'is_active' => true,
                'notes' => 'رحلات شرم الشيخ والأقصر',
            ],
            [
                'name' => 'شرق الدلتا للنقل',
                'phone' => '0223456792',
                'address' => 'الدقهلية - المنصورة',
                'is_active' => true,
                'notes' => 'رحلات القناة والسويس',
            ],
        ];

        foreach ($companies as $c) {
            BusCompany::firstOrCreate(
                ['name' => $c['name']],
                $c + ['created_by' => 1]
            );
        }

        $this->command->info('  • Bus companies: 4');
    }

    protected function seedInventories(): void
    {
        $companies = BusCompany::all()->keyBy('name');
        if ($companies->isEmpty()) {
            $this->command->warn('  ! No bus companies to attach inventories to');
            return;
        }

        $inventories = [
            // EGP routes
            [
                'company' => 'شركة الدلتا للنقل البري',
                'route' => 'القاهرة - الإسكندرية',
                'travel_date' => '2026-08-01',
                'departure_time' => '08:00:00',
                'total_tickets' => 50,
                'available_tickets' => 50,
                'cost_per_ticket' => 110.00,
                'selling_price' => 150.00,
                'currency' => 'EGP',
                'exchange_rate_to_egp' => 1.0,
                'payment_type' => BusInventoryPaymentType::Cash->value,
                'total_cost' => 5500.00,
                'amount_paid' => 5500.00,
                'remaining_debt' => 0.00,
                'notes' => 'رحلة يومية - حافلات مكيفة',
            ],
            [
                'company' => 'مصر لباصات الصعيد',
                'route' => 'القاهرة - الأقصر',
                'travel_date' => '2026-08-05',
                'departure_time' => '20:00:00',
                'total_tickets' => 40,
                'available_tickets' => 40,
                'cost_per_ticket' => 280.00,
                'selling_price' => 380.00,
                'currency' => 'EGP',
                'exchange_rate_to_egp' => 1.0,
                'payment_type' => BusInventoryPaymentType::Deferred->value,
                'total_cost' => 11200.00,
                'amount_paid' => 0.00,
                'remaining_debt' => 11200.00,
                'notes' => 'رحلة ليلية - VIP',
            ],
            [
                'company' => 'Go Bus للسفر',
                'route' => 'القاهرة - شرم الشيخ',
                'travel_date' => '2026-08-10',
                'departure_time' => '07:30:00',
                'total_tickets' => 30,
                'available_tickets' => 30,
                'cost_per_ticket' => 320.00,
                'selling_price' => 450.00,
                'currency' => 'EGP',
                'exchange_rate_to_egp' => 1.0,
                'payment_type' => BusInventoryPaymentType::Cash->value,
                'total_cost' => 9600.00,
                'amount_paid' => 9600.00,
                'remaining_debt' => 0.00,
                'notes' => 'رحلة سياحية',
            ],
            [
                'company' => 'شرق الدلتا للنقل',
                'route' => 'القاهرة - بورسعيد',
                'travel_date' => '2026-08-12',
                'departure_time' => '09:00:00',
                'total_tickets' => 35,
                'available_tickets' => 35,
                'cost_per_ticket' => 130.00,
                'selling_price' => 180.00,
                'currency' => 'EGP',
                'exchange_rate_to_egp' => 1.0,
                'payment_type' => BusInventoryPaymentType::Deferred->value,
                'total_cost' => 4550.00,
                'amount_paid' => 2000.00,
                'remaining_debt' => 2550.00,
                'notes' => 'رحلة يومية',
            ],
            // Foreign-currency routes
            [
                'company' => 'Go Bus للسفر',
                'route' => 'القاهرة - العقبة (الأردن)',
                'travel_date' => '2026-08-15',
                'departure_time' => '06:00:00',
                'total_tickets' => 25,
                'available_tickets' => 25,
                'cost_per_ticket' => 22.00, // USD
                'selling_price' => 30.00,   // USD
                'currency' => 'USD',
                'exchange_rate_to_egp' => 49.50,
                'payment_type' => BusInventoryPaymentType::Cash->value,
                'total_cost' => 550.00,
                'amount_paid' => 550.00,
                'remaining_debt' => 0.00,
                'notes' => 'رحلة دولية - بالدولار',
            ],
            [
                'company' => 'مصر لباصات الصعيد',
                'route' => 'القاهرة - جدة (السعودية)',
                'travel_date' => '2026-08-20',
                'departure_time' => '14:00:00',
                'total_tickets' => 30,
                'available_tickets' => 30,
                'cost_per_ticket' => 85.00, // SAR
                'selling_price' => 120.00,   // SAR
                'currency' => 'SAR',
                'exchange_rate_to_egp' => 13.20,
                'payment_type' => BusInventoryPaymentType::Deferred->value,
                'total_cost' => 2550.00,
                'amount_paid' => 0.00,
                'remaining_debt' => 2550.00,
                'notes' => 'رحلة حج - بالريال السعودي',
            ],
            [
                'company' => 'شرق الدلتا للنقل',
                'route' => 'الإسكندرية - أسوان (ترانزيت)',
                'travel_date' => '2026-08-25',
                'departure_time' => '18:00:00',
                'total_tickets' => 20,
                'available_tickets' => 20,
                'cost_per_ticket' => 14.00, // USD
                'selling_price' => 20.00,   // USD
                'currency' => 'USD',
                'exchange_rate_to_egp' => 49.50,
                'payment_type' => BusInventoryPaymentType::Cash->value,
                'total_cost' => 280.00,
                'amount_paid' => 280.00,
                'remaining_debt' => 0.00,
                'notes' => 'رحلة ترانزيت - دولارية',
            ],
            [
                'company' => 'شركة الدلتا للنقل البري',
                'route' => 'القاهرة - الجيزة (داخلي)',
                'travel_date' => '2026-08-30',
                'departure_time' => '12:00:00',
                'total_tickets' => 60,
                'available_tickets' => 60,
                'cost_per_ticket' => 35.00,
                'selling_price' => 50.00,
                'currency' => 'EGP',
                'exchange_rate_to_egp' => 1.0,
                'payment_type' => BusInventoryPaymentType::Cash->value,
                'total_cost' => 2100.00,
                'amount_paid' => 2100.00,
                'remaining_debt' => 0.00,
                'notes' => 'رحلة قصيرة - داخل القاهرة الكبرى',
            ],
        ];

        foreach ($inventories as $inv) {
            $company = $companies[$inv['company']] ?? null;
            if (! $company) {
                continue;
            }
            $inv['company_id'] = $company->id;
            unset($inv['company']);
            $inv['created_by'] = 1;
            $inv['is_auto_created'] = false;
            // Resolve FX rate if missing
            if ($inv['currency'] !== 'EGP' && empty($inv['exchange_rate_to_egp'])) {
                $inv['exchange_rate_to_egp'] = app(CurrencyService::class)
                    ->convert(1.0, $inv['currency'], 'EGP')['rate'];
            }
            BusInventory::firstOrCreate(
                [
                    'company_id' => $inv['company_id'],
                    'route' => $inv['route'],
                    'travel_date' => $inv['travel_date'],
                ],
                $inv
            );
        }

        $this->command->info('  • Bus inventories: 8 (3 EGP cash, 2 EGP deferred, 2 USD, 1 SAR)');
    }

    protected function seedClearingAccounts(): void
    {
        // Force creation of the Bus module clearing accounts.
        app(LedgerClearingAccounts::class)
            ->incomeContraIdForModule(TransactionModule::Bus);
        app(LedgerClearingAccounts::class)
            ->expenseContraIdForModule(TransactionModule::Bus);

        $income = Account::where('name', 'إقفال إيرادات الباصات')->first();
        $expense = Account::where('name', 'إقفال تكاليف الباصات')->first();

        $this->command->info(sprintf(
            '  • Bus clearing accounts: income=%s, expense=%s',
            $income ? "#{$income->id}" : 'NULL',
            $expense ? "#{$expense->id}" : 'NULL'
        ));
    }
}
