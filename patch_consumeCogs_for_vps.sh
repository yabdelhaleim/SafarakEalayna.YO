cat > /tmp/patch_consumeCogs.php << 'PATCH_EOF'
<?php
$file = '/var/www/safarakealayna/app/Services/Finance/PrepaidLedgerService.php';
$content = file_get_contents($file);

$old = <<<'OLD'
        // Guard: الرصيد المسبق يجب أن يكون كافياً قبل الخصم
        $prepaidAccount = Account::query()->find($prepaidId);
        if ($prepaidAccount && (float) $prepaidAccount->balance < $amount) {
            $available = (float) $prepaidAccount->balance;
OLD;

$new = <<<'NEW'
        // Guard: الرصيد المسبق يجب أن يكون كافياً قبل الخصم
        // Phase 4 fix: نقرأ من الـ ledger entries (الحقيقة) مش accounts.balance (المخزّن)
        $prepaidAccount = Account::query()->find($prepaidId);

        $ledgerBalance = (float) \Illuminate\Support\Facades\DB::table('account_entries')
            ->where('account_id', $prepaidId)
            ->whereNull('deleted_at')
            ->sum(\Illuminate\Support\Facades\DB::raw('credit - debit'));

        if ($prepaidAccount && $ledgerBalance < $amount) {
            $available = $ledgerBalance;
NEW;

if (str_contains($content, $old)) {
    $content = str_replace($old, $new, $content);
    file_put_contents($file, $content);
    echo "Patched OK\n";
} else {
    echo "Pattern not found\n";
    exit(1);
}
PATCH_EOF
php -l /tmp/patch_consumeCogs.php
