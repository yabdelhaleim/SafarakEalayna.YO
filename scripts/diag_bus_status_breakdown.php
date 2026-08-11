<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== bus_bookings status breakdown (deleted_at IS NULL) ===\n";

$rows = DB::select('
    SELECT status, COUNT(*) AS cnt
    FROM bus_bookings
    WHERE deleted_at IS NULL
    GROUP BY status
    ORDER BY cnt DESC
');

foreach ($rows as $r) {
    printf("  %-20s %d\n", $r->status, $r->cnt);
}

echo "\n=== Customers with ACTIVE bus bookings ===\n";

$active = DB::selectOne('
    SELECT COUNT(DISTINCT customer_id) AS n
    FROM bus_bookings
    WHERE deleted_at IS NULL
      AND status NOT IN (\'cancelled\')
');

echo "  Customers with active bus bookings: ".$active->n."\n";

echo "\n=== Customers in each status bucket ===\n";

foreach ($rows as $r) {
    $cust = DB::selectOne("
        SELECT COUNT(DISTINCT customer_id) AS n
        FROM bus_bookings
        WHERE deleted_at IS NULL
          AND status = ?
    ", [$r->status]);
    printf("  status=%-20s customers=%d  bookings=%d\n",
        $r->status, $cust->n, $r->cnt);
}

echo "\n=== Last 5 active bus bookings (if any) ===\n";

$last = DB::select('
    SELECT bb.id, bb.customer_id, c.full_name, bb.status, bb.created_at
    FROM bus_bookings bb
    LEFT JOIN customers c ON c.id = bb.customer_id
    WHERE bb.deleted_at IS NULL
      AND bb.status NOT IN (\'cancelled\')
    ORDER BY bb.created_at DESC
    LIMIT 5
');

if (empty($last)) {
    echo "  (none)\n";
} else {
    foreach ($last as $r) {
        printf("  #%-5d  customer_id=%-5d  name=%-25s  status=%-12s  created=%s\n",
            $r->id, $r->customer_id, $r->full_name ?? '-', $r->status, $r->created_at);
    }
}