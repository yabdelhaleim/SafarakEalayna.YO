<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Setting\PaymentMethod;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Enums\TransactionModule;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Production-readiness test seeder for the Online Services module.
 *
 * Idempotent. Seeds:
 *   - 6 service types (stamps, attestations, visas-online, training, gov, customs)
 *   - 5 service providers (Momtaz, Etidal, Masarat, Etimad, Absher)
 *   - 5 fresh test customers
 *   - Online cashbox (EGP 30,000) + USD cashbox (1,000)
 *   - Auto-created clearing accounts for online module
 */
class OnlineModuleProductionTestSeeder extends Seeder
{
    public function run(): void
    {
        Auth::loginUsingId(User::first()?->id ?? 1);

        DB::transaction(function () {
            $this->seedServiceTypes();
            $this->seedProviders();
            $this->seedCustomers();
            $this->seedOnlineCashboxes();
            $this->seedClearingAccounts();
            $this->seedPaymentMethods();
        });

        $this->command->info('✅ OnlineModuleProductionTestSeeder completed.');
    }

    protected function seedServiceTypes(): void
    {
        $types = [
            [
                'code' => 'stamps',
                'name_ar' => 'طوابع وضرائب',
                'name_en' => 'Stamps & Taxes',
                'color' => '#EF4444',
                'icon' => 'heroicon-o-document-duplicate',
                'description_ar' => 'شراء طوابع حكومية ودفع ضرائب',
                'description_en' => 'Government stamps and tax payments',
                'is_active' => 1, 'order' => 1,
            ],
            [
                'code' => 'attestations',
                'name_ar' => 'تصديقات',
                'name_en' => 'Document Attestations',
                'color' => '#3B82F6',
                'icon' => 'heroicon-o-document-check',
                'description_ar' => 'تصديق مستندات من الخارجية والسفارات',
                'description_en' => 'Document attestation from ministries',
                'is_active' => 1, 'order' => 2,
            ],
            [
                'code' => 'visas_online',
                'name_ar' => 'تأشيرات أونلاين',
                'name_en' => 'Online Visas',
                'color' => '#10B981',
                'icon' => 'heroicon-o-paper-airplane',
                'description_ar' => 'تأشيرات سياحية وعمل إلكترونية',
                'description_en' => 'Online tourist and work visas',
                'is_active' => 1, 'order' => 3,
            ],
            [
                'code' => 'training_courses',
                'name_ar' => 'دورات تدريبية',
                'name_en' => 'Training Courses',
                'color' => '#8B5CF6',
                'icon' => 'heroicon-o-academic-cap',
                'description_ar' => 'دورات تدريبية معتمدة (IELTS، TOEFL، دورات مهنية)',
                'description_en' => 'Certified training courses',
                'is_active' => 1, 'order' => 4,
            ],
            [
                'code' => 'gov_services',
                'name_ar' => 'خدمات حكومية',
                'name_en' => 'Government Services',
                'color' => '#F59E0B',
                'icon' => 'heroicon-o-building-library',
                'description_ar' => 'استخراج شهادات وأوراق رسمية',
                'description_en' => 'Certificates and official documents',
                'is_active' => 1, 'order' => 5,
            ],
            [
                'code' => 'customs_clearance',
                'name_ar' => 'تخليص جمركي',
                'name_en' => 'Customs Clearance',
                'color' => '#0EA5E9',
                'icon' => 'heroicon-o-truck',
                'description_ar' => 'تخليص شحنات وفسح جمركي',
                'description_en' => 'Shipment clearance and customs',
                'is_active' => 1, 'order' => 6,
            ],
        ];

        foreach ($types as $t) {
            OnlineServiceType::updateOrCreate(['code' => $t['code']], $t);
        }
        $this->command->info('  • Service types: 6 (stamps, attestations, visas, training, gov, customs)');
    }

    protected function seedProviders(): void
    {
        $providers = [
            [
                'code' => 'momtaz',
                'name_ar' => 'شركة ممتاز للخدمات',
                'name_en' => 'Momtaz Services Co.',
                'description_ar' => 'مزود خدمة الطوابع والتصديقات',
                'description_en' => 'Stamps and attestation services',
                'color' => '#EF4444',
                'icon' => 'heroicon-o-star',
                'contact_phone' => '01000000001',
                'contact_account' => 'EG1100000000000001',
                'is_active' => 1, 'order' => 1,
            ],
            [
                'code' => 'etidal',
                'name_ar' => 'شركة اعتدال للخدمات',
                'name_en' => 'Etidal Services Co.',
                'description_ar' => 'تأشيرات أونلاين',
                'description_en' => 'Online visas',
                'color' => '#3B82F6',
                'icon' => 'heroicon-o-globe-alt',
                'contact_phone' => '01000000002',
                'contact_account' => 'EG1100000000000002',
                'is_active' => 1, 'order' => 2,
            ],
            [
                'code' => 'masarat',
                'name_ar' => 'مسارات للدورات التدريبية',
                'name_en' => 'Masarat Training',
                'description_ar' => 'دورات تدريبية معتمدة',
                'description_en' => 'Certified training courses',
                'color' => '#8B5CF6',
                'icon' => 'heroicon-o-academic-cap',
                'contact_phone' => '01000000003',
                'contact_account' => 'EG1100000000000003',
                'is_active' => 1, 'order' => 3,
            ],
            [
                'code' => 'etimad',
                'name_ar' => 'اعتماد للخدمات الحكومية',
                'name_en' => 'Etimad Gov Services',
                'description_ar' => 'استخراج شهادات وأوراق رسمية',
                'description_en' => 'Certificates and official documents',
                'color' => '#F59E0B',
                'icon' => 'heroicon-o-shield-check',
                'contact_phone' => '01000000004',
                'contact_account' => 'EG1100000000000004',
                'is_active' => 1, 'order' => 4,
            ],
            [
                'code' => 'absher',
                'name_ar' => 'أبشر للتخليص الجمركي',
                'name_en' => 'Absher Customs',
                'description_ar' => 'تخليص جمركي وشحنات',
                'description_en' => 'Customs clearance',
                'color' => '#0EA5E9',
                'icon' => 'heroicon-o-truck',
                'contact_phone' => '01000000005',
                'contact_account' => 'EG1100000000000005',
                'is_active' => 1, 'order' => 5,
            ],
        ];

        foreach ($providers as $p) {
            OnlineServiceProvider::updateOrCreate(['code' => $p['code']], $p);
        }
        $this->command->info('  • Service providers: 5 (Momtaz, Etidal, Masarat, Etimad, Absher)');
    }

    protected function seedCustomers(): void
    {
        $customers = [
            [
                'full_name' => 'عميل أونلاين - علي محمد',
                'phone' => '01620020001',
                'email' => 'online1@example.com',
                'national_id' => '30201011234567',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'EG',
                'city' => 'القاهرة',
            ],
            [
                'full_name' => 'عميل أونلاين - هند صالح',
                'phone' => '01620020002',
                'email' => 'online2@example.com',
                'national_id' => '30202021234567',
                'type' => 'individual',
                'customer_tier' => 'PREMIUM',
                'nationality' => 'EG',
                'city' => 'الإسكندرية',
            ],
            [
                'full_name' => 'عميل أونلاين - خالد العمري',
                'phone' => '01620020003',
                'email' => 'online3@example.com',
                'national_id' => '30203031234567',
                'type' => 'individual',
                'customer_tier' => 'VIP',
                'nationality' => 'SA',
                'city' => 'الرياض',
            ],
            [
                'full_name' => 'عميل أونلاين - سلطان الخليوي',
                'phone' => '01620020004',
                'email' => 'online4@example.com',
                'national_id' => '30204041234567',
                'type' => 'individual',
                'customer_tier' => 'AGENT',
                'nationality' => 'KW',
                'city' => 'الكويت',
            ],
            [
                'full_name' => 'شركة الفجر للتجارة',
                'phone' => '01620020005',
                'email' => 'fajr@example.com',
                'national_id' => '30205051234567',
                'type' => 'company',
                'customer_tier' => 'AGENT',
                'nationality' => 'EG',
                'city' => 'الجيزة',
            ],
        ];

        foreach ($customers as $c) {
            Customer::updateOrCreate(['phone' => $c['phone']], $c);
        }
        $this->command->info('  • Online customers: 5 (mixed EGP/SA/KW + corporate)');
    }

    protected function seedOnlineCashboxes(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            Account::firstOrCreate(
                ['name' => 'خزينة الخدمات الإلكترونية النقدية'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 30000.00,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة الخدمات الإلكترونية لاستلام تحصيلات العملاء',
                    'created_by' => 1,
                ]
            );

            Account::firstOrCreate(
                ['name' => 'خزينة الخدمات الإلكترونية الدولارية'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 1000.00,
                    'currency' => 'USD',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة الخدمات الإلكترونية بالدولار',
                    'created_by' => 1,
                ]
            );
        });
        $this->command->info('  • Online cashboxes: EGP (30,000) + USD (1,000)');
    }

    protected function seedClearingAccounts(): void
    {
        $lc = app(LedgerClearingAccounts::class);
        $incomeId = $lc->incomeContraIdForModule(TransactionModule::Online);
        $expenseId = $lc->expenseContraIdForModule(TransactionModule::Online);

        $this->command->info(sprintf(
            '  • Online clearing accounts: income=#%s, expense=#%s',
            $incomeId ?? 'NULL',
            $expenseId ?? 'NULL'
        ));
    }

    protected function seedPaymentMethods(): void
    {
        $methods = [
            ['code' => 'cash', 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'color' => '#10B981', 'is_active' => 1, 'order' => 1],
            ['code' => 'bank_transfer', 'name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'color' => '#3B82F6', 'is_active' => 1, 'order' => 2],
            ['code' => 'cash_wallet', 'name_ar' => 'محفظة كاش', 'name_en' => 'Cash Wallet', 'color' => '#F59E0B', 'is_active' => 1, 'order' => 3],
            ['code' => 'postal_transfer', 'name_ar' => 'حوالة بريدية', 'name_en' => 'Postal Transfer', 'color' => '#6366F1', 'is_active' => 1, 'order' => 4],
            ['code' => 'office_safe', 'name_ar' => 'خزينة المكتب', 'name_en' => 'Office Safe', 'color' => '#0EA5E9', 'is_active' => 1, 'order' => 5],
            ['code' => 'office_drawer', 'name_ar' => 'درج المكتب', 'name_en' => 'Office Drawer', 'color' => '#06B6D4', 'is_active' => 1, 'order' => 6],
        ];

        foreach ($methods as $m) {
            PaymentMethod::firstOrCreate(['code' => $m['code']], $m);
        }
        $this->command->info('  • Shared payment_methods: 6 (cash, bank, wallet, postal, office_safe, office_drawer)');
    }
}
