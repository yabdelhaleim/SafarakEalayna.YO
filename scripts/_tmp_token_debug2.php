<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

// 1. Create token via same DB context
$admin = User::where('role', 'admin')->first();
$token = $admin->createToken('test-' . uniqid())->plainTextToken;
echo "Token created: " . substr($token, 0, 30) . "...\n";

// 2. Check the token was stored in DB
$tokens = DB::table('personal_access_tokens')->where('tokenable_id', $admin->id)->orderBy('id', 'desc')->limit(3)->get();
echo "Tokens in DB (latest 3 for admin):\n";
foreach ($tokens as $t) {
    echo "  id={$t->id} name={$t->name} hash=" . substr($t->token, 0, 20) . "...\n";
}

// 3. Try hitting a route via raw curl equivalent through Laravel HTTP client
echo "\n--- Test: GET /api/v1/bus/companies ---\n";
$resp = Http::withToken($token)->acceptJson()->get('http://127.0.0.1:8000/api/v1/bus/companies');
echo "Status: " . $resp->status() . "\n";
echo "Body: " . substr($resp->body(), 0, 200) . "\n";

// 4. Try with explicit Authorization Bearer header
echo "\n--- Test: GET with Authorization: Bearer header ---\n";
$resp = Http::withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
    ->get('http://127.0.0.1:8000/api/v1/bus/companies');
echo "Status: " . $resp->status() . "\n";
echo "Body: " . substr($resp->body(), 0, 200) . "\n";

// 5. Try login flow instead
echo "\n--- Test: POST /api/v1/auth/login ---\n";
$resp = Http::acceptJson()->post('http://127.0.0.1:8000/api/v1/auth/login', [
    'email' => $admin->email,
    'password' => 'password',
]);
echo "Status: " . $resp->status() . "\n";
echo "Body: " . substr($resp->body(), 0, 400) . "\n";
