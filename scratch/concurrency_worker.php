<?php

// Concurrency Worker Process
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$workerId = $argv[1] ?? 'worker_0';
$scenario = $argv[2] ?? '';
$payloadRaw = $argv[3] ?? '';
$payloadJson = base64_decode($payloadRaw);
$payload = json_decode($payloadJson, true);

$startTime = microtime(true);
$result = [
    'worker_id' => $workerId,
    'scenario' => $scenario,
    'start_time' => $startTime,
    'end_time' => 0,
    'duration_ms' => 0,
    'status' => 0,
    'success' => false,
    'error' => null,
    'data' => null,
];

try {
    DB::reconnect();

    $admin = User::first() ?? User::create(['name' => 'Worker Admin', 'email' => 'worker@example.com', 'password' => bcrypt('password'), 'role' => 'admin']);
    $token = $admin->createToken('worker-token-'.$workerId)->plainTextToken;

    Auth::forgetGuards();

    $uri = '';
    $method = 'POST';
    $reqData = [];

    switch ($scenario) {
        case 'INVENTORY_BOOKING_RACE':
            $uri = '/api/v1/bus/bookings';
            $reqData = [
                'inventory_id' => $payload['inventory_id'] ?? null,
                'customer_id' => $payload['customer_id'] ?? null,
                'quantity' => $payload['quantity'] ?? 1,
            ];
            break;

        case 'PAYMENT_RACE':
            $uri = "/api/v1/bus/bookings/{$payload['booking_id']}/pay";
            $reqData = [
                'amount' => $payload['amount'],
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'account_id' => $payload['account_id'],
            ];
            break;

        case 'CANCEL_RACE':
            $uri = "/api/v1/bus/bookings/{$payload['booking_id']}/cancel";
            $reqData = [
                'company_penalty' => $payload['company_penalty'] ?? 0,
                'office_penalty' => $payload['office_penalty'] ?? 0,
                'account_id' => $payload['account_id'] ?? null,
            ];
            break;

        case 'SUPPLIER_DEBT_RACE':
            $uri = "/api/v1/bus/companies/{$payload['company_id']}/pay-debt";
            $reqData = [
                'amount' => $payload['amount'],
                'from_account_id' => $payload['from_account_id'],
            ];
            break;

        default:
            throw new Exception("Invalid worker scenario: {$scenario}");
    }

    $req = Request::create($uri, $method, $reqData);
    $req->headers->set('Accept', 'application/json');
    $req->headers->set('Authorization', 'Bearer '.$token);

    $res = $app->handle($req);
    $status = $res->getStatusCode();
    $body = $res->getContent();
    $json = json_decode($body, true);

    $result['status'] = $status;
    $result['data'] = $json;
    $result['success'] = ($status === 200 || $status === 201);
    if (! $result['success']) {
        $result['error'] = is_array($json) ? ($json['message'] ?? $body) : substr($body, 0, 300);
    }
} catch (Throwable $e) {
    $result['status'] = 500;
    $result['error'] = $e->getMessage();
    $result['success'] = false;
}

$endTime = microtime(true);
$result['end_time'] = $endTime;
$result['duration_ms'] = round(($endTime - $startTime) * 1000, 2);

echo json_encode($result);
