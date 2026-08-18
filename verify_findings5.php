<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Flight debt state (without paid_amount column) ===" . PHP_EOL;
$rows = DB::table('flight_bookings as b')
    ->leftJoin('flight_payments as p', 'p.flight_booking_id', '=', 'b.id')
    ->groupBy('b.id')
    ->select('b.id', 'b.selling_price', DB::raw('SUM(p.amount) paid_sum'))
    ->where('b.booking_number', 'NOT LIKE', 'TOURISM_FULL_AUDIT_20260818_%')
    ->orderBy('b.id', 'desc')
    ->limit(8)->get();
foreach ($rows as $r) {
    $debt = (float)($r->selling_price ?? 0) - (float)($r->paid_sum ?? 0);
    printf("  booking=%d selling=%s paid_sum=%s -> debt=%s" . PHP_EOL,
        $r->id, $r->selling_price, $r->paid_sum ?? 'NULL', $debt);
}

echo PHP_EOL . "=== Arabic error msg string in FlightBookingService ===" . PHP_EOL;
echo shell_exec("grep -rn 'يجب اختيار حساب الصرف' app/ 2>/dev/null | head -3");

echo PHP_EOL . "=== audit_logs columns ===" . PHP_EOL;
foreach (DB::select('SHOW COLUMNS FROM audit_logs') as $c) {
    echo "  " . $c->Field . " (" . $c->Type . ")" . PHP_EOL;
}

echo PHP_EOL . "=== HajjUmra bookings count + account_id state ===" . PHP_EOL;
echo "  total hajj_umra_bookings: " . DB::table('hajj_umra_bookings')->count() . PHP_EOL;
echo "  with account_id IS NULL: " . DB::table('hajj_umra_bookings')->whereNull('account_id')->count() . PHP_EOL;
echo "  latest 5:" . PHP_EOL;
foreach (DB::table('hajj_umra_bookings')->orderBy('id', 'desc')->limit(5)->get() as $b) {
    printf("    id=%d status=%s account_id=%s" . PHP_EOL, $b->id, $b->status, $b->account_id ?? 'NULL');
}
