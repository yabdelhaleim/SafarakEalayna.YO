<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight E2E Recharge — يشحن أرصدة الناقلين من الحسابات البنكية (نفس العملة)
 * ════════════════════════════════════════════════════════════════════════════
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE = 'http://127.0.0.1:8000/api/v1';
$ids = json_decode(file_get_contents(storage_path('logs/flight_e2e_ids.json')), true);

Auth::login(User::find($ids['admin_user_id']));

// تأكد من وجود حساب SAR
$sarAccount = Account::firstOrCreate(
    ['name' => 'بنك مصر Flight E2E SAR', 'type' => 'bank', 'currency' => 'SAR'],
    [
        'bank_name' => 'بنك مصر',
        'account_number' => 'EG-FLIGHT-E2E-SAR-001',
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'tourism',
        'module' => 'flights',
        'is_module_vault' => true,
        'balance' => 100000,
        'created_by' => $ids['admin_user_id'],
        'notes' => 'حساب SAR للاختبار E2E',
    ]
);
$ids['accounts']['bank_sar'] = $sarAccount->id;
echo "✅ SAR account ID={$sarAccount->id} (balance={$sarAccount->balance})\n";

// تأكد من وجود حساب KWD
$kwdAccount = Account::firstOrCreate(
    ['name' => 'بنك Flight E2E KWD', 'type' => 'bank', 'currency' => 'KWD'],
    [
        'bank_name' => 'بنك',
        'account_number' => 'EG-FLIGHT-E2E-KWD-001',
        'is_active' => true,
        'owner_type' => 'office',
        'module_type' => 'tourism',
        'module' => 'flights',
        'is_module_vault' => true,
        'balance' => 10000,
        'created_by' => $ids['admin_user_id'],
        'notes' => 'حساب KWD بنكي للاختبار E2E',
    ]
);
$ids['accounts']['bank_kwd'] = $kwdAccount->id;
echo "✅ KWD bank account ID={$kwdAccount->id} (balance={$kwdAccount->balance})\n";

file_put_contents(
    storage_path('logs/flight_e2e_ids.json'),
    json_encode($ids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// شحن الناقلين
$resp = Http::acceptJson()->post("$BASE/auth/login", [
    'email' => 'admin@safarakealayna.com',
    'password' => 'Sf@2026#Admin!',
]);
$token = $resp->json('data.token');
echo "✅ Auth token acquired\n\n";

// شحن كل ناقل من حسابه بنفس العملة
$recharges = [
    [
        'carrier' => $ids['carriers']['egyptair'],
        'from_account' => $ids['accounts']['bank_egp'],
        'amount' => 100000,
        'note' => 'EgyptAir EGP — 100K EGP',
    ],
    [
        'carrier' => $ids['carriers']['jazeera_kwd'],
        'from_account' => $ids['accounts']['bank_kwd'],
        'amount' => 2000,
        'note' => 'Jazeera KWD — 2K KWD',
    ],
    [
        'carrier' => $ids['carriers']['saudi_sar'],
        'from_account' => $ids['accounts']['bank_sar'],
        'amount' => 50000,
        'note' => 'Saudi SAR — 50K SAR',
    ],
];

foreach ($recharges as $r) {
    $carrier = FlightCarrier::find($r['carrier']);
    $from = Account::find($r['from_account']);
    $bal_before = (float) $from->balance;
    $cbal_before = (float) $carrier->balance;

    $resp = Http::withToken($token)->acceptJson()->post(
        "$BASE/flight/carriers/{$r['carrier']}/recharge",
        [
            'from_account_id' => $r['from_account'],
            'amount' => $r['amount'],
            'notes' => $r['note'],
        ]
    );

    if ($resp->successful()) {
        $d = $resp->json('data');
        echo "✅ Recharged {$carrier->name}: {$cbal_before} → " . $d['carrier']['balance'] . " {$carrier->currency} (Δ +{$r['amount']})\n";
        echo "   Source: {$from->name} {$bal_before} → " . $d['source_account']['balance'] . " (Δ -{$r['amount']})\n";
    } else {
        echo "❌ Recharge FAILED for {$carrier->name}: " . $resp->status() . ' ' . substr($resp->body(), 0, 200) . "\n";
    }
}

echo "\n✅ Done. Carrier balances after recharge:\n";
foreach (FlightCarrier::all() as $c) {
    echo "   {$c->name} ({$c->code}) = {$c->balance} {$c->currency} (credit_limit: {$c->credit_limit})\n";
}
