<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->where('is_active', true)->first();
if (! $user) {
    fwrite(STDERR, "No active user found\n");
    exit(1);
}

$token = $user->createToken('scratch-test')->plainTextToken;

$tests = [
    ['GET', '/api/v1/flight/bookings/212'],
    ['GET', '/api/v1/flight/bookings/1'],
    ['GET', '/api/v1/flight/bookings'],
];

foreach ($tests as [$method, $path]) {
    $request = Illuminate\Http\Request::create($path, $method);
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer '.$token);

    $response = $app->handle($request);
    $body = json_decode($response->getContent(), true);

    echo $method.' '.$path.PHP_EOL;
    echo '  status: '.$response->getStatusCode().PHP_EOL;
    echo '  message: '.($body['message'] ?? $body['status'] ?? 'n/a').PHP_EOL;
    if (isset($body['data']['id'])) {
        echo '  booking_id: '.$body['data']['id'].PHP_EOL;
        echo '  booking_number: '.($body['data']['booking_number'] ?? 'n/a').PHP_EOL;
    } elseif (isset($body['data']['items'])) {
        echo '  items_count: '.count($body['data']['items']).PHP_EOL;
        if (! empty($body['data']['items'][0]['id'])) {
            echo '  first_id: '.$body['data']['items'][0]['id'].PHP_EOL;
            echo '  first_number: '.($body['data']['items'][0]['booking_number'] ?? 'n/a').PHP_EOL;
        }
    }
    echo PHP_EOL;
}
