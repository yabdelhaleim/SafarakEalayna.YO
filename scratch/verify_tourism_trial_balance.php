<?php

/**
 * تحقق سريع من ميزان السياحة على MySQL (بدون RefreshDatabase).
 * التشغيل: php scratch/verify_tourism_trial_balance.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$treasury = app(\App\Services\Finance\TreasuryService::class);
$tb = $treasury->getTrialBalance();

$current = ($tb['total_balances'] + $tb['total_liquidity'] + $tb['due_to_us']) - $tb['due_from_us'];
$expected = $tb['base_capital'] + $tb['profits'];
$equationOk = abs($current - (float) $tb['current_capital']) < 0.02
    && abs($expected - (float) $tb['expected_capital']) < 0.02
    && abs(((float) $tb['current_capital'] - (float) $tb['expected_capital']) - (float) $tb['variance']) < 0.02;

echo "=== ميزان حسابات قسم السياحة (MySQL) ===\n";
printf("طيران (أرصدة):     %s EGP\n", number_format($tb['details']['flight_balances'], 2));
printf("حج/عمرة (أرصدة):  %s EGP\n", number_format($tb['details']['hajj_umra_balances'], 2));
printf("تأشيرات (أرصدة):  %s EGP\n", number_format($tb['details']['visa_balances'], 2));
printf("إجمالي الأرصدة:   %s EGP\n", number_format($tb['total_balances'], 2));
printf("السيولة:          %s EGP\n", number_format($tb['total_liquidity'], 2));
printf("لنا:              %s EGP\n", number_format($tb['due_to_us'], 2));
printf("علينا:            %s EGP\n", number_format($tb['due_from_us'], 2));
printf("رأس المال الفعلي: %s EGP\n", number_format($tb['current_capital'], 2));
printf("رأس المال الأساسي:%s EGP\n", number_format($tb['base_capital'], 2));
printf("الأرباح الصافية:  %s EGP\n", number_format($tb['profits'], 2));
printf("رأس المال المتوقع:%s EGP\n", number_format($tb['expected_capital'], 2));
printf("الفرق:            %s EGP\n", number_format($tb['variance'], 2));
printf("الحالة:           %s\n", $tb['status']);
printf("معادلة الميزان:   %s\n", $equationOk ? 'OK' : 'FAIL');

exit($equationOk ? 0 : 1);
