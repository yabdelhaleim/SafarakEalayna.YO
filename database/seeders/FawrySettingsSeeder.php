<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Fawry\FawryCurrency;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Setting\Currency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FawrySettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed EGP Currency if not present
        $egp = Currency::updateOrCreate(
            ['code' => 'EGP'],
            [
                'name_ar' => 'جنيه مصري',
                'name_en' => 'Egyptian Pound',
                'symbol' => 'ج.م',
                'exchange_rate' => 1.0000,
                'is_active' => true,
                'order' => 1,
            ]
        );

        // Seed SAR and USD as well for general currency lookup
        Currency::updateOrCreate(
            ['code' => 'USD'],
            [
                'name_ar' => 'دولار أمريكي',
                'name_en' => 'US Dollar',
                'symbol' => '$',
                'exchange_rate' => 48.0000,
                'is_active' => true,
                'order' => 2,
            ]
        );

        Currency::updateOrCreate(
            ['code' => 'SAR'],
            [
                'name_ar' => 'ريال سعودي',
                'name_en' => 'Saudi Riyal',
                'symbol' => 'ر.س',
                'exchange_rate' => 12.8000,
                'is_active' => true,
                'order' => 3,
            ]
        );

        // 2. Seed default liquidity accounts for fawry
        $cashboxAccount = Account::updateOrCreate(
            ['name' => 'خزينة فوري الرئيسية'],
            [
                'type' => 'cashbox',
                'module_type' => 'fawry',
                'balance' => 10000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        $walletAccount = Account::updateOrCreate(
            ['name' => 'محفظة فوري كاش'],
            [
                'type' => 'wallet',
                'module_type' => 'fawry',
                'balance' => 5000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01012345678',
                'created_by' => 1,
            ]
        );

        $bankAccount = Account::updateOrCreate(
            ['name' => 'بنك فوري الأهلي'],
            [
                'type' => 'bank',
                'module_type' => 'fawry',
                'balance' => 20000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        // 3. Seed default fawry machines
        FawryMachine::updateOrCreate(
            ['name' => 'ماكينة فوري الرئيسية'],
            [
                'type' => 'fawry',
                'balance' => 15000.00,
                'is_active' => true,
                'notes' => 'ماكينة فوري الصفراء الرئيسية للمكتب',
            ]
        );

        FawryMachine::updateOrCreate(
            ['name' => 'ماكينة أمان المكتب'],
            [
                'type' => 'aman',
                'balance' => 5000.00,
                'is_active' => true,
                'notes' => 'ماكينة أمان الاحتياطية',
            ]
        );

        // 4. Seed operation types
        $operationTypes = [
            [
                'code' => 'withdrawal',
                'name_ar' => 'سحب نقدية',
                'name_en' => 'Withdrawal',
                'color' => 'error',
                'icon' => 'heroicon-o-arrow-down-left',
                'description_ar' => 'سحب نقدية من حساب العميل بالماكينة',
                'description_en' => 'Withdraw cash from client account using machine',
                'order' => 1,
            ],
            [
                'code' => 'deposit',
                'name_ar' => 'إيداع / شحن رصيد',
                'name_en' => 'Deposit / Cash In',
                'color' => 'success',
                'icon' => 'heroicon-o-arrow-up-right',
                'description_ar' => 'شحن رصيد لحساب أو محفظة العميل من الماكينة',
                'description_en' => 'Deposit/Cash In to client account or wallet',
                'order' => 2,
            ],
            [
                'code' => 'payment',
                'name_ar' => 'سداد مدفوعات / فواتير',
                'name_en' => 'Bill Payment',
                'color' => 'info',
                'icon' => 'heroicon-o-document-text',
                'description_ar' => 'سداد فواتير مرافق أو خدمات من الماكينة للعميل',
                'description_en' => 'Pay bills or services from machine for client',
                'order' => 3,
            ],
            [
                'code' => 'travel_permit',
                'name_ar' => 'تصريح سفر / جهات',
                'name_en' => 'Travel Permit / Entity',
                'color' => 'warning',
                'icon' => 'heroicon-o-ticket',
                'description_ar' => 'سداد تصاريح سفر أو مستحقات جهات عبر فوري',
                'description_en' => 'Pay travel permits or entity dues via Fawry',
                'order' => 4,
            ],
        ];

        foreach ($operationTypes as $ot) {
            FawryOperationType::updateOrCreate(
                ['code' => $ot['code']],
                [
                    'name_ar' => $ot['name_ar'],
                    'name_en' => $ot['name_en'],
                    'color' => $ot['color'],
                    'icon' => $ot['icon'],
                    'description_ar' => $ot['description_ar'],
                    'description_en' => $ot['description_en'],
                    'is_active' => true,
                    'order' => $ot['order'],
                ]
            );
        }

        // 5. Seed payment methods and link default accounts
        $paymentMethods = [
            [
                'code' => 'cash',
                'name_ar' => 'نقدي',
                'name_en' => 'Cash',
                'color' => 'success',
                'icon' => 'heroicon-o-banknotes',
                'description_ar' => 'تحصيل كاش يوضع في الخزينة الرئيسية مباشرة',
                'description_en' => 'Cash payment collected directly to the main safe',
                'provider_name' => 'خزينة المكتب',
                'default_account_id' => $cashboxAccount->id,
                'order' => 1,
            ],
            [
                'code' => 'bank_transfer',
                'name_ar' => 'تحويل بنكي',
                'name_en' => 'Bank Transfer',
                'color' => 'info',
                'icon' => 'heroicon-o-building-library',
                'description_ar' => 'تحويل بنكي مباشر لحساب الشركة بالبنك الأهلي',
                'description_en' => 'Direct bank transfer to company NBE account',
                'provider_name' => 'البنك الأهلي المصري',
                'bank_name' => 'البنك الأهلي المصري',
                'account_number' => '1234567890123456',
                'default_account_id' => $bankAccount->id,
                'order' => 2,
            ],
            [
                'code' => 'cash_wallet',
                'name_ar' => 'محفظة إلكترونية (كاش)',
                'name_en' => 'E-Wallet',
                'color' => 'purple',
                'icon' => 'heroicon-o-phone',
                'description_ar' => 'تحويل إلكتروني فوري على محفظة فودافون كاش',
                'description_en' => 'Instant electronic transfer to Vodafone Cash wallet',
                'provider_name' => 'فودافون كاش',
                'phone_number' => '01012345678',
                'default_account_id' => $walletAccount->id,
                'order' => 3,
            ],
        ];

        foreach ($paymentMethods as $pm) {
            FawryPaymentMethod::updateOrCreate(
                ['code' => $pm['code']],
                [
                    'name_ar' => $pm['name_ar'],
                    'name_en' => $pm['name_en'],
                    'color' => $pm['color'],
                    'icon' => $pm['icon'],
                    'description_ar' => $pm['description_ar'],
                    'description_en' => $pm['description_en'],
                    'provider_name' => $pm['provider_name'],
                    'bank_name' => $pm['bank_name'] ?? null,
                    'account_number' => $pm['account_number'] ?? null,
                    'phone_number' => $pm['phone_number'] ?? null,
                    'default_account_id' => $pm['default_account_id'],
                    'is_active' => true,
                    'order' => $pm['order'],
                ]
            );
        }

        // 6. Seed Fawry Currency config
        FawryCurrency::updateOrCreate(
            ['currency_id' => $egp->id],
            [
                'exchange_rate' => 1.0000,
                'min_amount' => 1.00,
                'max_amount' => 50000.00,
                'fee_percent' => 1.50, // 1.5% fee
                'fixed_fee' => 2.00,  // 2 EGP fixed fee
                'is_active' => true,
                'order' => 1,
            ]
        );
    }
}
