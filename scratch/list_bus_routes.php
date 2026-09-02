<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

echo "=== ALL BUS API ROUTES ===\n";
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (str_contains($uri, 'bus')) {
        $methods = implode('|', $route->methods());
        $action = $route->getActionName();
        $middleware = implode(',', $route->gatherMiddleware());
        echo "{$methods} /{$uri} -> {$action} | MW: {$middleware}\n";
    }
}
