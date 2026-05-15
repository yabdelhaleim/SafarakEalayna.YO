$admin = \App\Models\User::where('role', 'admin')->first();
$employee = \App\Models\User::where('role', 'employee')->first();

$endpoints = [
    ['GET', '/api/v1/dashboard', 'Dashboard'],
    ['GET', '/api/v1/finance/accounts', 'Finance'],
    ['GET', '/api/v1/reports/financial/summary', 'Reports'],
    ['GET', '/api/v1/users', 'Users'],
];

foreach ($endpoints as [$method, $uri, $name]) {
    echo "--- $name ($uri) ---\n";
    
    // 1. Unauth
    Auth::forgetUser();
    \Laravel\Sanctum\Sanctum::actingAs(new \App\Models\User(), []); // Clear actingAs
    $request = \Illuminate\Http\Request::create($uri, $method);
    $request->headers->set('Accept', 'application/json');
    $response = app()->handle($request);
    echo "  Unauth: " . $response->getStatusCode() . "\n";
    
    // 2. Employee
    \Laravel\Sanctum\Sanctum::actingAs($employee, ['*']);
    $request = \Illuminate\Http\Request::create($uri, $method);
    $request->headers->set('Accept', 'application/json');
    $response = app()->handle($request);
    echo "  Employee: " . $response->getStatusCode() . "\n";
    
    // 3. Admin
    \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);
    $request = \Illuminate\Http\Request::create($uri, $method);
    $request->headers->set('Accept', 'application/json');
    $response = app()->handle($request);
    echo "  Admin: " . $response->getStatusCode() . "\n";
    if ($response->getStatusCode() === 500) {
        echo "  ERROR: " . substr($response->getContent(), 0, 500) . "\n";
    }
}
