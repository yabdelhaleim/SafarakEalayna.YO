<?php
/**
 * FIX: Seed payment methods + Online module accounts
 * Run: php fix_online_payment_methods.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting\PaymentMethod;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

echo "=== ONLINE MODULE FIX: Payment Methods + Accounts ===\n\n";

DB::beginTransaction();
try {
    // ── 1. Seed payment methods ──────────────────────────────────────────
    echo "1️⃣  Seeding payment methods...\n";
    $methods = [
        ['code' => 'cash',           'name_ar' => 'نقدي',            'name_en' => 'Cash',           'color' => '#10B981', 'is_active' => 1, 'order' => 1],
        ['code' => 'bank_transfer',  'name_ar' => 'تحويل بنكي',      'name_en' => 'Bank Transfer',  'color' => '#3B82F6', 'is_active' => 1, 'order' => 2],
        ['code' => 'cash_wallet',    'name_ar' => 'محفظة كاش',       'name_en' => 'Cash Wallet',    'color' => '#F59E0B', 'is_active' => 1, 'order' => 3],
        ['code' => 'vodafone_cash',  'name_ar' => 'فودافون كاش',     'name_en' => 'Vodafone Cash',  'color' => '#EF4444', 'is_active' => 1, 'order' => 4],
        ['code' => 'instapay',       'name_ar' => 'إنستاباي',         'name_en' => 'InstaPay',       'color' => '#8B5CF6', 'is_active' => 1, 'order' => 5],
        ['code' => 'postal_transfer','name_ar' => 'حوالة بريدية',    'name_en' => 'Postal Transfer', 'color' => '#6366F1', 'is_active' => 1, 'order' => 6],
        ['code' => 'credit_card',    'name_ar' => 'بطاقة ائتمان',    'name_en' => 'Credit Card',    'color' => '#0EA5E9', 'is_active' => 1, 'order' => 7],
        ['code' => 'office_safe',    'name_ar' => 'خزينة المكتب',    'name_en' => 'Office Safe',    'color' => '#06B6D4', 'is_active' => 1, 'order' => 8],
    ];

    $created = 0;
    $skipped = 0;
    foreach ($methods as $m) {
        $existing = PaymentMethod::where('code', $m['code'])->first();
        if (!$existing) {
            PaymentMethod::create($m);
            echo "   ✅ Created: {$m['code']} ({$m['name_ar']})\n";
            $created++;
        } else {
            echo "   ⏭️  Exists:  {$m['code']} ({$m['name_ar']})\n";
            $skipped++;
        }
    }
    echo "   → Created: {$created} | Skipped: {$skipped}\n\n";

    // ── 2. Ensure Online module cashbox account exists ────────────────────
    echo "2️⃣  Checking Online module accounts...\n";
    $onlineAccounts = Account::whereIn('module_type', ['online', 'office'])
        ->where('is_active', true)
        ->get(['id', 'name', 'type', 'module_type', 'balance']);

    echo "   Existing online/office accounts:\n";
    foreach ($onlineAccounts as $a) {
        $typeVal = $a->type instanceof \BackedEnum ? $a->type->value : $a->type;
        echo "   - ID:{$a->id} | {$a->name} | type:{$typeVal} | module:{$a->module_type}\n";
    }

    // Check if we need to create an online cashbox
    $hasCashbox = $onlineAccounts->contains(function ($a) {
        $typeVal = $a->type instanceof \BackedEnum ? $a->type->value : $a->type;
        return $typeVal === 'cashbox' && $a->module_type === 'online';
    });

    if (!$hasCashbox) {
        echo "\n   ⚠️  No online cashbox found. Creating default online cashbox...\n";

        // Find clearing accounts for online module
        $onlineClearingAccount = Account::where('module_type', 'online')
            ->where('type', 'clearing')
            ->first();

        // Get the parent accounts structure to understand what to do
        // We need a proper online cashbox - checking what account types are available
        $onlineCashbox = Account::create([
            'name'        => 'خزينة الخدمات الإلكترونية',
            'type'        => 'cashbox',
            'module_type' => 'online',
            'is_active'   => true,
            'balance'     => 0,
            'currency'    => 'EGP',
        ]);
        echo "   ✅ Created online cashbox: ID {$onlineCashbox->id}\n";
    } else {
        echo "\n   ✅ Online cashbox already exists.\n";
    }

    DB::commit();
    echo "\n=== ✅ FIX APPLIED SUCCESSFULLY ===\n\n";

    // ── 3. Final verification ─────────────────────────────────────────────
    echo "3️⃣  Final verification:\n\n";
    echo "Payment Methods:\n";
    $finalMethods = PaymentMethod::where('is_active', true)->orderBy('order')->get();
    foreach ($finalMethods as $m) {
        $at = \App\Support\Finance\PaymentMethodAccountType::resolve($m->code);
        echo "  [{$m->order}] {$m->code} → '{$m->name_ar}' → account_type:" . ($at ? $at->value : 'NULL') . "\n";
    }

    echo "\nOnline/Office Accounts:\n";
    $finalAccounts = Account::whereIn('module_type', ['online', 'office'])
        ->where('is_active', true)
        ->orderBy('name')
        ->get(['id', 'name', 'type', 'module_type', 'balance']);
    foreach ($finalAccounts as $a) {
        $typeVal = $a->type instanceof \BackedEnum ? $a->type->value : $a->type;
        echo "  ID:{$a->id} | {$a->name} | type:{$typeVal} | module:{$a->module_type} | balance:{$a->balance}\n";
    }

} catch (\Exception $e) {
    DB::rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
