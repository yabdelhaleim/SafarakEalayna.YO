<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== Phase 9 cancellation finding: cashbox required when refund > 0 ===" . PHP_EOL;
$svc = file_get_contents('app/Services/Flight/FlightBookingService.php');
$start = strpos($svc, 'public function cancelBooking');
if ($start === false) $start = strpos($svc, 'function cancel');
$excerpt = substr($svc, $start, 600);
echo substr($excerpt, 0, 600) . PHP_EOL;

echo PHP_EOL . "=== Phase 9 message: 'يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل' ===" . PHP_EOL;
$grep = shell_exec("grep -rn 'يجب اختيار حساب الصرف' app/ 2>/dev/null | head -3");
echo $grep;

echo PHP_EOL . "=== Phase 7 refund audit_logs.related_id missing column — how does RefundService log? ===" . PHP_EOL;
echo shell_exec("grep -rn \"'related_id'\\|\\\"related_id\\\"\\|->related_id\" app/Services/Flight/RefundService.php app/Services/Finance/RefundAuditLogger.php 2>/dev/null | head -10");

echo PHP_EOL . "=== Phase 5 debt finding: 'Zero EGP diff: flight debt step 1 (cash 2000)' ===" . PHP_EOL;
echo "  Audit reported: value=3000 → value=-2000  diff=5000  — does the real service match the audit scenario?" . PHP_EOL;

echo PHP_EOL . "=== Real debt state on existing flight bookings ===" . PHP_EOL;
$rows = DB::table('flight_bookings as b')
    ->leftJoin('flight_payments as p', 'p.flight_booking_id', '=', 'b.id')
    ->groupBy('b.id')
    ->select('b.id', 'b.selling_price', 'b.paid_amount', DB::raw('SUM(p.amount) paid_sum'))
    ->where('b.booking_number', 'NOT LIKE', 'TOURISM_FULL_AUDIT_20260818_%')
    ->orderBy('b.id', 'desc')
    ->limit(8)->get();
foreach ($rows as $r) {
    $debt = ($r->selling_price ?? 0) - ($r->paid_sum ?? 0);
    printf("  booking=%d selling=%s paid_amount(col)=%s paid_sum(query)=%s -> debt=%s" . PHP_EOL,
        $r->id, $r->selling_price, $r->paid_amount ?? 'NULL', $r->paid_sum ?? 'NULL', $debt);
}

echo PHP_EOL . "=== Real hajj_umra bookings currently in DB ===" . PHP_EOL;
echo "  count: " . DB::table('hajj_umra_bookings')->count() . PHP_EOL;
echo "  count with audit prefix: " . DB::table('hajj_umra_bookings')->where('notes', 'like', 'TOURISM_FULL_AUDIT_20260818_%')->count() . PHP_EOL;
echo "  count with hajj_umra_bookings.account_id IS NULL: " . DB::table('hajj_umra_bookings')->whereNull('account_id')->count() . PHP_EOL;
