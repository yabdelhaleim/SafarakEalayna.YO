<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\FawryOperationType as FawryOpEnum;
use App\Enums\FawryPaymentMethod as FawryPmEnum;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Setting\Currency as CurrencyModel;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Fawry\FawryCurrency;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Production-readiness test seeder for the Fawry module.
 *
 * Idempotent — re-runnable. Seeds:
 *   - 5 currencies (currencies table — required by fawry_currencies FK)
 *   - 6 fawry currencies (FK wrappers for each supported currency)
 *   - 4 operation types (withdrawal, deposit, payment, travel_permit)
 *   - 6 payment methods (vodafone_cash, etisalat_cash, orange_cash, we_pay,
 *     bank_transfer, credit_card) — backed by GL accounts
 *   - 3 fawry machines (Fawry, Aman, Masary) with EGP balances
 *   - Prepaid Fawry asset account (auto-created via LedgerClearingAccounts)
 *   - 5 fresh Fawry test customers
 */
class FawryModuleProductionTestSeeder extends Seeder
{
    public function run(): void
    {
        Auth::loginUsingId(User::first()?->id ?? 1);

        DB::transaction(function () {
            $this->seedCurrencies();
            $this->seedFawryCurrencies();
            $this->seedOperationTypes();
            $this->seedPaymentMethods();
            $this->seedPrepaidAccounts();
            $this->seedFawryCashboxes();
            $this->seedMachines();
            $this->seedFawryCustomers();
        });

        $this->command->info('✅ FawryModuleProductionTestSeeder completed.');
    }

    protected function seedCurrencies(): void
    {
        $rows = [
            ['code' => 'EGP', 'name_ar' => 'الجنيه المصري', 'name_en' => 'Egyptian Pound', 'symbol' => 'ج.م', 'exchange_rate' => 1.0000, 'is_active' => 1, 'order' => 1],
            ['code' => 'USD', 'name_ar' => 'الدولار الأمريكي', 'name_en' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 49.50, 'is_active' => 1, 'order' => 2],
            ['code' => 'SAR', 'name_ar' => 'الريال السعودي', 'name_en' => 'Saudi Riyal', 'symbol' => 'ر.س', 'exchange_rate' => 13.20, 'is_active' => 1, 'order' => 3],
            ['code' => 'EUR', 'name_ar' => 'اليورو', 'name_en' => 'Euro', 'symbol' => '€', 'exchange_rate' => 53.80, 'is_active' => 1, 'order' => 4],
            ['code' => 'KWD', 'name_ar' => 'الدينار الكويتي', 'name_en' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'exchange_rate' => 161.50, 'is_active' => 1, 'order' => 5],
        ];

        foreach ($rows as $r) {
            CurrencyModel::updateOrCreate(['code' => $r['code']], $r);
        }
        $this->command->info('  • Currencies: 5 (EGP, USD, SAR, EUR, KWD)');
    }

    protected function seedFawryCurrencies(): void
    {
        $currencies = CurrencyModel::all()->keyBy('code');
        $rows = [
            ['code' => 'EGP', 'exchange_rate' => 1.0, 'fee_percent' => 1.50, 'fixed_fee' => 1.00, 'min_amount' => 10, 'max_amount' => 50000],
            ['code' => 'USD', 'exchange_rate' => 49.50, 'fee_percent' => 2.00, 'fixed_fee' => 0.50, 'min_amount' => 1, 'max_amount' => 1000],
            ['code' => 'SAR', 'exchange_rate' => 13.20, 'fee_percent' => 1.50, 'fixed_fee' => 0.50, 'min_amount' => 5, 'max_amount' => 5000],
            ['code' => 'EUR', 'exchange_rate' => 53.80, 'fee_percent' => 2.00, 'fixed_fee' => 0.50, 'min_amount' => 1, 'max_amount' => 1000],
            ['code' => 'KWD', 'exchange_rate' => 161.50, 'fee_percent' => 1.00, 'fixed_fee' => 0.20, 'min_amount' => 1, 'max_amount' => 500],
        ];

        foreach ($rows as $r) {
            $currency = $currencies[$r['code']] ?? null;
            if (! $currency) {
                continue;
            }
            FawryCurrency::updateOrCreate(
                ['currency_id' => $currency->id],
                [
                    'exchange_rate' => $r['exchange_rate'],
                    'fee_percent' => $r['fee_percent'],
                    'fixed_fee' => $r['fixed_fee'],
                    'min_amount' => $r['min_amount'],
                    'max_amount' => $r['max_amount'],
                    'is_active' => 1,
                    'order' => 0,
                ]
            );
        }
        $this->command->info('  • Fawry currencies: 5 (with fee tiers)');
    }

    protected function seedOperationTypes(): void
    {
        $types = [
            [
                'code' => FawryOpEnum::Withdrawal->value,
                'name_ar' => 'سحب',
                'name_en' => 'Withdrawal',
                'color' => '#EF4444',
                'icon' => 'heroicon-o-arrow-down-tray',
                'description_ar' => 'سحب كاش من ماكينة الفوري للعميل (خصم من رصيد الماكينة، تحصيل من العميل + ربح الشركة)',
                'description_en' => 'Cash withdrawal from Fawry machine',
                'is_active' => 1, 'order' => 1,
            ],
            [
                'code' => FawryOpEnum::Deposit->value,
                'name_ar' => 'إيداع',
                'name_en' => 'Deposit',
                'color' => '#10B981',
                'icon' => 'heroicon-o-arrow-up-tray',
                'description_ar' => 'إيداع كاش في ماكينة الفوري (دفع العميل لنا، تغذية رصيد الماكينة)',
                'description_en' => 'Cash deposit into Fawry machine',
                'is_active' => 1, 'order' => 2,
            ],
            [
                'code' => FawryOpEnum::Payment->value,
                'name_ar' => 'سداد',
                'name_en' => 'Payment',
                'color' => '#3B82F6',
                'icon' => 'heroicon-o-credit-card',
                'description_ar' => 'سداد فواتير (كهرباء، مياه، غاز، إنترنت) عبر فوري',
                'description_en' => 'Bill payment via Fawry',
                'is_active' => 1, 'order' => 3,
            ],
            [
                'code' => FawryOpEnum::TravelPermit->value,
                'name_ar' => 'تصريح سفر',
                'name_en' => 'Travel Permit',
                'color' => '#8B5CF6',
                'icon' => 'heroicon-o-paper-airplane',
                'description_ar' => 'استخراج تصريح سفر عبر فوري',
                'description_en' => 'Travel permit issuance via Fawry',
                'is_active' => 1, 'order' => 4,
            ],
        ];

        foreach ($types as $t) {
            FawryOperationType::updateOrCreate(['code' => $t['code']], $t);
        }
        $this->command->info('  • Operation types: 4 (withdrawal, deposit, payment, travel_permit)');
    }

    protected function seedPaymentMethods(): void
    {
        $paymentMethods = [
            [
                'code' => FawryPmEnum::Cash->value,
                'name_ar' => 'نقدي',
                'name_en' => 'Cash',
                'color' => '#10B981',
                'provider_name' => 'المكتب',
                'is_active' => 1, 'order' => 1,
            ],
            [
                'code' => FawryPmEnum::BankTransfer->value,
                'name_ar' => 'تحويل بنكي',
                'name_en' => 'Bank Transfer',
                'color' => '#3B82F6',
                'provider_name' => 'CIB',
                'bank_name' => 'البنك التجاري الدولي',
                'is_active' => 1, 'order' => 2,
            ],
            [
                'code' => FawryPmEnum::CashWallet->value,
                'name_ar' => 'محفظة فوري',
                'name_en' => 'Fawry Wallet',
                'color' => '#F59E0B',
                'provider_name' => 'فوري',
                'is_active' => 1, 'order' => 3,
            ],
            [
                'code' => FawryPmEnum::OfficeSafe->value,
                'name_ar' => 'خزينة المكتب',
                'name_en' => 'Office Safe',
                'color' => '#0EA5E9',
                'provider_name' => 'المكتب',
                'is_active' => 1, 'order' => 5,
            ],
            [
                'code' => FawryPmEnum::OfficeDrawer->value,
                'name_ar' => 'درج المكتب',
                'name_en' => 'Office Drawer',
                'color' => '#06B6D4',
                'provider_name' => 'المكتب',
                'is_active' => 1, 'order' => 6,
            ],
            [
                'code' => 'instapay',
                'name_ar' => 'إنستاباي',
                'name_en' => 'InstaPay',
                'color' => '#EC4899',
                'provider_name' => 'InstaPay',
                'is_active' => 1, 'order' => 4,
            ],
        ];

        foreach ($paymentMethods as $pm) {
            FawryPaymentMethod::updateOrCreate(['code' => $pm['code']], $pm);
        }
        $this->command->info('  • Payment methods: 6 (cash, bank, wallet, postal, office_safe, office_drawer)');
    }

    protected function seedPrepaidAccounts(): void
    {
        // Auto-create the Fawry clearing + prepaid accounts
        $lc = app(LedgerClearingAccounts::class);
        $incomeId = $lc->incomeContraIdForModule(\App\Enums\TransactionModule::Fawry);
        $expenseId = $lc->expenseContraIdForModule(\App\Enums\TransactionModule::Fawry);
        $prepaidId = $lc->prepaidAccountId('fawry');

        $this->command->info(sprintf(
            '  • Fawry clearing accounts: income=#%s, expense=#%s, prepaid=#%s',
            $incomeId ?? 'NULL',
            $expenseId ?? 'NULL',
            $prepaidId ?? 'NULL'
        ));
    }

    protected function seedFawryCashboxes(): void
    {
        // Seed a dedicated EGP Fawry cashbox (for receipts)
        LedgerBalanceMutationGuard::run(function () {
            $cashbox = Account::firstOrCreate(
                ['name' => 'خزينة فوري النقدية'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 50000.00,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة فوري لاستلام تحصيلات العملاء',
                    'created_by' => 1,
                ]
            );

            // Fawry USD cashbox
            Account::firstOrCreate(
                ['name' => 'خزينة فوري الدولارية'],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 2000.00,
                    'currency' => 'USD',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'خزينة فوري بالدولار',
                    'created_by' => 1,
                ]
            );
        });
        $this->command->info('  • Fawry cashboxes: EGP (50,000) + USD (2,000)');
    }

    protected function seedMachines(): void
    {
        $machines = [
            [
                'name' => 'ماكينة فوري - الفرع الرئيسي',
                'type' => 'fawry',
                'balance' => 25000.00,
                'is_active' => 1,
                'notes' => 'ماكينة فوري بالمقر الرئيسي — رصيد ابتدائي 25,000 ج.م',
            ],
            [
                'name' => 'ماكينة أمان - الفرع الرئيسي',
                'type' => 'aman',
                'balance' => 15000.00,
                'is_active' => 1,
                'notes' => 'ماكينة أمان (سحب/إيداع) — رصيد ابتدائي 15,000 ج.م',
            ],
            [
                'name' => 'ماكينة مصاري - الفرع الرئيسي',
                'type' => 'masary',
                'balance' => 8000.00,
                'is_active' => 1,
                'notes' => 'ماكينة مصاري — رصيد ابتدائي 8,000 ج.م',
            ],
            [
                'name' => 'ماكينة فوري - معطلة (للاختبار)',
                'type' => 'fawry',
                'balance' => 1000.00,
                'is_active' => 0,
                'notes' => 'ماكينة معطلة مؤقتاً — لاختبار الفلتر is_active',
            ],
        ];

        foreach ($machines as $m) {
            FawryMachine::updateOrCreate(
                ['name' => $m['name']],
                $m
            );
        }
        $this->command->info('  • Fawry machines: 4 (3 active + 1 inactive for testing)');
    }

    protected function seedFawryCustomers(): void
    {
        $customers = [
            [
                'full_name' => 'عميل فوري - أحمد فؤاد',
                'phone' => '01510010001',
                'email' => 'fawry1@example.com',
                'national_id' => '30101011234567',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'EG',
                'city' => 'القاهرة',
            ],
            [
                'full_name' => 'عميل فوري - منى السري',
                'phone' => '01510010002',
                'email' => 'fawry2@example.com',
                'national_id' => '30102021234567',
                'type' => 'individual',
                'customer_tier' => 'PREMIUM',
                'nationality' => 'EG',
                'city' => 'الإسكندرية',
            ],
            [
                'full_name' => 'عميل فوري - خالد العمري',
                'phone' => '01510010003',
                'email' => 'fawry3@example.com',
                'national_id' => '30103031234567',
                'type' => 'individual',
                'customer_tier' => 'STANDARD',
                'nationality' => 'SA',
                'city' => 'الرياض',
            ],
            [
                'full_name' => 'عميل فوري - سامي الخليوي',
                'phone' => '01510010004',
                'email' => 'fawry4@example.com',
                'national_id' => '30104041234567',
                'type' => 'individual',
                'customer_tier' => 'VIP',
                'nationality' => 'KW',
                'city' => 'الكويت',
            ],
            [
                'full_name' => 'عميل فوري - شركة الأمل',
                'phone' => '01510010005',
                'email' => 'amal@example.com',
                'national_id' => '30105051234567',
                'type' => 'company',
                'customer_tier' => 'AGENT',
                'nationality' => 'EG',
                'city' => 'الجيزة',
            ],
        ];

        foreach ($customers as $c) {
            Customer::updateOrCreate(['phone' => $c['phone']], $c);
        }
        $this->command->info('  • Fawry customers: 5 (mixed EGP/SA/KW, individual/corporate)');
    }
}
