<?php

$file = 'C:\travile\SafarakEalayna\scripts\flight_module_e2e_audit.php';
$code = file_get_contents($file);
if ($code === false) {
    echo "Cannot read file\n";
    exit(1);
}

// 1. Replace USD rate 50.0 with 49.5
$code = str_replace('$rate = 50.0;', '$rate = 49.5;', $code);

// 2. After every addPayment($booking, [...]) add confirmBooking($booking->fresh());
//    Use a more reliable pattern: find addPayment calls (greedy across nested brackets)
$pattern = '/(\$svc->addPayment\(\$booking,\s*\[[^;]*?\]\);)(\s*(?:\$booking->refresh\(\);|assertSame|\$svc->confirmBooking|\}))/s';
$code = preg_replace_callback($pattern, function ($m) {
    if (strpos($m[2], 'confirmBooking') !== false) {
        return $m[0]; // already has it
    }

    return $m[1]."\n    \$svc->confirmBooking(\$booking->fresh());".$m[2];
}, $code);

// 3. cancelBooking: penalty_amount → airline_penalty, refund_amount → office_penalty=0
$code = preg_replace_callback('/\$svc->cancelBooking\(\$booking,\s*\[(.*?)\]\);/s', function ($m) {
    $body = $m[1];
    // Replace penalty_amount → airline_penalty
    $body = preg_replace("/'penalty_amount'\s*=>\s*([^,\n]+),/", "'airline_penalty' => $1,", $body);
    // Replace refund_amount line with comment
    $body = preg_replace("/'refund_amount'\s*=>\s*[^,\n]+,/", '// refund_amount computed from total_paid - penalties', $body);

    return '$svc->cancelBooking($booking, ['.$body.']);';
}, $code);

// 4. createRefundRequest: booking_id → flight_booking_id
$code = str_replace("'booking_id'         => \$booking->id,", "'flight_booking_id' => \$booking->id,", $code);
$code = str_replace("'booking_id' => \$booking->id,", "'flight_booking_id' => \$booking->id,", $code);

// 5. S16 modification reverse: check deleted_at not status
$code = str_replace(
    "    assertSame('S16', 'modification.status reversed', 'reversed', \$mod->status->value ?? \$mod->status);",
    "    \$modTrashed = TicketModification::withTrashed()->find(\$mod->id);\n    assertSame('S16', 'modification soft-deleted after reverse', true, (bool) \$modTrashed->deleted_at);",
    $code
);

file_put_contents($file, $code);
echo "DONE\n";
echo 'USD rate fixes: '.substr_count($code, '$rate = 49.5;')."\n";
echo 'confirmBooking added: '.substr_count($code, '$svc->confirmBooking($booking->fresh());')."\n";
echo 'airline_penalty count: '.substr_count($code, "'airline_penalty'")."\n";
echo 'flight_booking_id count: '.substr_count($code, "'flight_booking_id'")."\n";
echo "remaining 'booking_id' in refund context: ".substr_count($code, "'booking_id' =>")."\n";
