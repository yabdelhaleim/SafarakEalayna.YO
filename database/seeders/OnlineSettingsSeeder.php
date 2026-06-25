<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Setting\PaymentMethod;
use App\Models\Setting\Currency;
use Illuminate\Database\Seeder;

class OnlineSettingsSeeder extends Seeder
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

        // 2. Seed default liquidity accounts for online module
        $cashboxAccount = Account::updateOrCreate(
            ['name' => 'خزينة الخدمات الإلكترونية الرئيسية'],
            [
                'type' => 'cashbox',
                'module_type' => 'online',
                'balance' => 15000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        $walletAccount = Account::updateOrCreate(
            ['name' => 'محفظة فودافون كاش - خدمات إلكترونية'],
            [
                'type' => 'wallet',
                'module_type' => 'online',
                'balance' => 10000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01011112222',
                'created_by' => 1,
            ]
        );

        $bankAccount = Account::updateOrCreate(
            ['name' => 'بنك إلكتروني الأهلي'],
            [
                'type' => 'bank',
                'module_type' => 'online',
                'balance' => 30000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        // 3. Seed payment methods
        $paymentMethods = [
            [
                'code' => 'cash',
                'name_ar' => 'نقدي',
                'name_en' => 'Cash',
                'color' => '#10B981',
                'order' => 1,
            ],
            [
                'code' => 'bank_transfer',
                'name_ar' => 'تحويل بنكي',
                'name_en' => 'Bank Transfer',
                'color' => '#3B82F6',
                'order' => 2,
            ],
            [
                'code' => 'vodafone_cash',
                'name_ar' => 'فودافون كاش',
                'name_en' => 'Vodafone Cash',
                'color' => '#EF4444',
                'order' => 3,
            ],
            [
                'code' => 'instapay',
                'name_ar' => 'إنستا باي',
                'name_en' => 'InstaPay',
                'color' => '#8B5CF6',
                'order' => 4,
            ],
        ];

        foreach ($paymentMethods as $pm) {
            PaymentMethod::updateOrCreate(
                ['code' => $pm['code']],
                [
                    'name_ar' => $pm['name_ar'],
                    'name_en' => $pm['name_en'],
                    'color' => $pm['color'],
                    'is_active' => true,
                    'order' => $pm['order'],
                ]
            );
        }

        // 4. Seed online service types
        $serviceTypes = [
            [
                'code' => 'travel_permit',
                'name_ar' => 'تصريح سفر',
                'name_en' => 'Travel Permit',
                'description_ar' => 'استخراج تصريح سفر أمني أو عسكري',
                'color' => '#F59E0B',
                'icon' => 'heroicon-o-ticket',
                'order' => 1,
            ],
            [
                'code' => 'recharge_card',
                'name_ar' => 'كارت شحن / باقة',
                'name_en' => 'Recharge Card',
                'description_ar' => 'شحن باقات إنترنت ومكالمات وكروت شحن',
                'color' => '#10B981',
                'icon' => 'heroicon-o-device-phone-mobile',
                'order' => 2,
            ],
            [
                'code' => 'bill_payment',
                'name_ar' => 'دفع فواتير',
                'name_en' => 'Bill Payment',
                'description_ar' => 'دفع فواتير غاز، مياه، كهرباء، وإنترنت منزلي',
                'color' => '#3B82F6',
                'icon' => 'heroicon-o-document-text',
                'order' => 3,
            ],
        ];

        foreach ($serviceTypes as $st) {
            OnlineServiceType::updateOrCreate(
                ['code' => $st['code']],
                [
                    'name_ar' => $st['name_ar'],
                    'name_en' => $st['name_en'],
                    'description_ar' => $st['description_ar'],
                    'color' => $st['color'],
                    'icon' => $st['icon'],
                    'is_active' => true,
                    'order' => $st['order'],
                    'created_by' => 1,
                ]
            );
        }

        // 5. Seed online service providers
        $providers = [
            [
                'code' => 'vodafone',
                'name_ar' => 'فودافون مصر',
                'name_en' => 'Vodafone Egypt',
                'description_ar' => 'مزود خدمات الاتصالات فودافون',
                'color' => '#EF4444',
                'icon' => 'heroicon-o-globe-alt',
                'contact_phone' => '16888',
                'default_purchase_account_id' => $walletAccount->id,
                'order' => 1,
            ],
            [
                'code' => 'etisalat',
                'name_ar' => 'اتصالات من e&',
                'name_en' => 'Etisalat Egypt',
                'description_ar' => 'مزود خدمات الاتصالات اتصالات',
                'color' => '#10B981',
                'icon' => 'heroicon-o-globe-alt',
                'contact_phone' => '333',
                'default_purchase_account_id' => $cashboxAccount->id,
                'order' => 2,
            ],
            [
                'code' => 'egypt_post',
                'name_ar' => 'البريد المصري',
                'name_en' => 'Egypt Post',
                'description_ar' => 'الخدمات البريدية والحكومية للبريد المصري',
                'color' => '#065F46',
                'icon' => 'heroicon-o-envelope',
                'contact_phone' => '16789',
                'default_purchase_account_id' => $bankAccount->id,
                'order' => 3,
            ],
        ];

        foreach ($providers as $pr) {
            OnlineServiceProvider::updateOrCreate(
                ['code' => $pr['code']],
                [
                    'name_ar' => $pr['name_ar'],
                    'name_en' => $pr['name_en'],
                    'description_ar' => $pr['description_ar'],
                    'color' => $pr['color'],
                    'icon' => $pr['icon'],
                    'contact_phone' => $pr['contact_phone'],
                    'default_purchase_account_id' => $pr['default_purchase_account_id'],
                    'is_active' => true,
                    'order' => $pr['order'],
                    'created_by' => 1,
                ]
            );
        }
    }
}
