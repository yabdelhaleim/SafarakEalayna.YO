<?php

$code = file_get_contents('C:\travile\SafarakEalayna\scripts\flight_module_e2e_audit.php');
echo "rate = occurrences:\n";
$pos = 0;
while (($pos = strpos($code, 'rate =', $pos)) !== false) {
    echo '  '.substr($code, $pos, 25)."\n";
    $pos += 6;
}
echo '---addPayment count: '.substr_count($code, 'addPayment')."\n";
echo '---penalty_amount count: '.substr_count($code, 'penalty_amount')."\n";
echo '---refund_amount count: '.substr_count($code, 'refund_amount')."\n";
echo '---booking_id => count: '.substr_count($code, "'booking_id' =>")."\n";
echo '---flight_booking_id count: '.substr_count($code, 'flight_booking_id')."\n";
