<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class TourismAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Flight Accounts
        Account::updateOrCreate(
            ['name' => 'خزينة الطيران الرئيسية'],
            [
                'type' => 'cashbox',
                'module_type' => 'flights',
                'balance' => 25000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'بنك الطيران الأهلي'],
            [
                'type' => 'bank',
                'module_type' => 'flights',
                'balance' => 50000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة الطيران كاش'],
            [
                'type' => 'wallet',
                'module_type' => 'flights',
                'balance' => 5000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01033334444',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'إيرادات الطيران'],
            [
                'type' => 'revenue',
                'module_type' => 'flights',
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'تكاليف الطيران'],
            [
                'type' => 'expense',
                'module_type' => 'flights',
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        // 2. Seed Visa Accounts
        Account::updateOrCreate(
            ['name' => 'خزينة التأشيرات الرئيسية'],
            [
                'type' => 'cashbox',
                'module_type' => 'visas',
                'balance' => 15000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'بنك التأشيرات الأهلي'],
            [
                'type' => 'bank',
                'module_type' => 'visas',
                'balance' => 30000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة التأشيرات كاش'],
            [
                'type' => 'wallet',
                'module_type' => 'visas',
                'balance' => 5000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01055556666',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'إيرادات التأشيرات'],
            [
                'type' => 'revenue',
                'module_type' => 'visas',
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'تكاليف التأشيرات'],
            [
                'type' => 'expense',
                'module_type' => 'visas',
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        // 3. Seed Bus Accounts
        Account::updateOrCreate(
            ['name' => 'خزينة الباصات الرئيسية'],
            [
                'type' => 'cashbox',
                'module_type' => 'bus',
                'balance' => 10000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'بنك الباصات الأهلي'],
            [
                'type' => 'bank',
                'module_type' => 'bus',
                'balance' => 20000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'محفظة الباصات كاش'],
            [
                'type' => 'wallet',
                'module_type' => 'bus',
                'balance' => 5000.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01077778888',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'إيرادات الباصات'],
            [
                'type' => 'revenue',
                'module_type' => 'bus',
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );

        Account::updateOrCreate(
            ['name' => 'تكاليف الباصات'],
            [
                'type' => 'expense',
                'module_type' => 'bus',
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => 'office',
                'created_by' => 1,
            ]
        );
    }
}
