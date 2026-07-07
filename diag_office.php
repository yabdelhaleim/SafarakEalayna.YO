<?php
use App\Models\Account;
use App\Models\Customer;
use App\Services\Reports\FinancialReportService;
use App\Services\Finance\TreasuryService;

echo "\n========== [1] OFFICE CUSTOMER ACCOUNTS STATE ==========\n";

// كل الـ customer accounts اللي module_type = office
$officeCustAccounts = Account::query()
    ->where('type', 'customer')
    ->whereIn('module_type', ['office', 'bus', 'fawry', 'online', 'wallet', null])
    ->get(['id', 'name', 'balance', 'currency', 'module_type']);

$positive = $officeCustAccounts->where('balance', '>', 0);
$negative = $officeCustAccounts->where('balance', '<', 0);
$zero = $officeCustAccounts->where('balance', '=', 0);

echo "Total customer accounts (office-related): " . $officeCustAccounts->count() . "\n";
echo "  Balance > 0 (لنا فلوس): " . $positive->count() . " — Sum: " . number_format((float)$positive->sum('balance'), 2) . " EGP\n";
echo "  Balance < 0 (علينا فلوس): " . $negative->count() . " — Sum: " . number_format((float)$negative->sum('balance'), 2) . " EGP\n";
echo "  Balance = 0: " . $zero->count() . "\n";

echo "\n[Top 10 office customer accounts with positive balance:]\n";
foreach ($positive->sortByDesc('balance')->take(10) as $a) {
    $cust = Customer::where('account_id', $a->id)->first();
    printf("  Acc#%-4d | %-40s | balance=%.2f %s | cust=%s\n",
        $a->id, mb_substr($a->name, 0, 40), (float)$a->balance, $a->currency,
        $cust?->full_name ?? '?');
}

echo "\n========== [2] getDebtsReport(office) ==========\n";
$svc = app(FinancialReportService::class);
$report = $svc->getDebtsReport(['department' => 'office']);
echo "Items returned: " . count($report['items'] ?? []) . "\n";
echo "Receivables: " . number_format((float)($report['totals']['receivables'] ?? 0), 2) . " EGP\n";
echo "Payables: " . number_format((float)($report['totals']['payables'] ?? 0), 2) . " EGP\n";

echo "\n[First 15 items:]\n";
foreach (array_slice($report['items'] ?? [], 0, 15) as $item) {
    printf("  ID=%s | %-25s | bal=%10.2f %s | dept=%s | type=%s\n",
        $item['id'] ?? '?',
        mb_substr(($item['entity_name'] ?? $item['name'] ?? '?'), 0, 25),
        (float)($item['balance'] ?? 0),
        $item['currency'] ?? '?',
        $item['department'] ?? '?',
        $item['entity_type'] ?? '?');
}

echo "\n========== [3] calculateReceivablesAndPayables('office') ==========\n";
$result = app(TreasuryService::class)->calculateReceivablesAndPayables('office');
printf("due_to_us (المتحقق لنا): %.2f EGP\n", $result['due_to_us']);
printf("due_from_us (المستحق علينا): %.2f EGP\n", $result['due_from_us']);

echo "\n========== [4] TRIAL_BALANCE_RECEIVABLE_ENTITY_TYPES ==========\n";
echo "Currently: ['customer', 'flight_group']\n";
echo "Customers in office dept that are NOT in this whitelist get SKIPPED.\n";

echo "\n========== [5] ENTITY_TYPES IN OFFICE REPORT ==========\n";
$typeCounts = [];
foreach ($report['items'] ?? [] as $item) {
    $t = $item['entity_type'] ?? 'null';
    $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
}
foreach ($typeCounts as $t => $c) {
    $inWhitelist = in_array($t, ['customer', 'flight_group'], true) ? '(IN whitelist)' : '(EXCLUDED)';
    echo "  $t: $c items $inWhitelist\n";
}

echo "\n========== DONE ==========\n";
