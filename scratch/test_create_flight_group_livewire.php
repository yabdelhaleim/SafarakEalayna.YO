<?php

use App\Filament\Admin\Resources\FlightGroups\FlightGroupResource;
use App\Models\Flight\FlightCarrier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Livewire\Component;

chdir(__DIR__.'/..');
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';

$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');

$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

config(['database.default' => 'sqlite']);
config(['database.connections.sqlite.database' => ':memory:']);

try {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $user = User::create([
        'name' => 'Admin',
        'email' => 'admin@test.com',
        'password' => bcrypt('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);

    $carrier = FlightCarrier::create([
        'name' => 'Test Carrier',
        'code' => 'TC',
        'currency' => 'EGP',
        'credit_limit' => 0,
        'is_active' => true,
        'created_by' => $user->id,
    ]);

    // Try Livewire test simulation
    $test = new class extends Component
    {
        public function render()
        {
            return '<div></div>';
        }
    };

    // Try to instantiate the CreatePage via app()
    $panel = Filament::getPanel('admin');
    echo 'Panel: '.($panel ? $panel->getId() : 'null')."\n";

    if ($panel) {
        echo "Discovered resources:\n";
        $resources = $panel->getResources();
        foreach ($resources as $r) {
            $name = $r::getModelLabel() ?? '?';
            echo "  - $r => $name\n";
        }
    }

    // Check if FlightGroupResource is registered
    $isRegistered = $panel && in_array(FlightGroupResource::class, $panel->getResources(), true);
    echo "\nFlightGroupResource registered: ".($isRegistered ? 'YES' : 'NO')."\n";

    // Try to render the create page using Filament's standard mechanism
    $request = Request::create('/admin/flight-groups/create', 'GET');
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    // Use Filament::getUrl
    try {
        $url = FlightGroupResource::getUrl('create');
        echo "Create URL: $url\n";
    } catch (Throwable $e) {
        echo 'URL error: '.$e->getMessage()."\n";
    }

} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage()."\n";
    echo 'FILE: '.$e->getFile().':'.$e->getLine()."\n";
    echo "--- TRACE ---\n";
    foreach (array_slice($e->getTrace(), 0, 15) as $i => $t) {
        $class = $t['class'] ?? '';
        $type = $t['type'] ?? '';
        $function = $t['function'] ?? '';
        $file = $t['file'] ?? '?';
        $line = $t['line'] ?? '?';
        echo "#$i $class$type$function at $file:$line\n";
    }
}
