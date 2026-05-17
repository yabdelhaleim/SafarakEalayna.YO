<?php

/**
 * Safarak Ealayna - Production Cache & OPcache Flusher
 * visiting this file via browser will completely purge OPcache and Laravel caches.
 */

// Allow only authorized access or simple token if needed (optional).
// For convenience, we will let it run and display a styled success dashboard.

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

$status = [];

// 1. Clear PHP OPcache
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        $status[] = ['name' => 'PHP OPcache', 'status' => 'Success 🟢', 'details' => 'Reset complete. In-memory bytecode cache cleared.'];
    } else {
        $status[] = ['name' => 'PHP OPcache', 'status' => 'Warning 🟡', 'details' => 'OPcache is enabled but could not be reset programmatically.'];
    }
} else {
    $status[] = ['name' => 'PHP OPcache', 'status' => 'Info 🔵', 'details' => 'OPcache extension is not enabled or function is restricted.'];
}

// 2. Bootstrap Laravel Console
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 3. Clear Laravel Caches
$commands = [
    'config:clear' => 'Configuration Cache',
    'route:clear'  => 'Routes Cache',
    'view:clear'   => 'Compiled Blade Views Cache',
    'cache:clear'  => 'Application Data Cache',
    'filament:clear-cached-components' => 'Filament Component Cache',
];

foreach ($commands as $command => $label) {
    try {
        Artisan::call($command);
        $status[] = ['name' => $label, 'status' => 'Success 🟢', 'details' => trim(Artisan::output()) ?: 'Cache cleared successfully.'];
    } catch (\Exception $e) {
        $status[] = ['name' => $label, 'status' => 'Failed 🔴', 'details' => $e->getMessage()];
    }
}

// 4. Delete bootstrap/cache/blade-icons.php and public/hot
$bladeIconsCache = base_path('bootstrap/cache/blade-icons.php');
if (File::exists($bladeIconsCache)) {
    if (File::delete($bladeIconsCache)) {
        $status[] = ['name' => 'Blade Icons Cache File', 'status' => 'Success 🟢', 'details' => 'Stale blade-icons.php deleted successfully.'];
    } else {
        $status[] = ['name' => 'Blade Icons Cache File', 'status' => 'Failed 🔴', 'details' => 'Could not delete cached icons file.'];
    }
} else {
    $status[] = ['name' => 'Blade Icons Cache File', 'status' => 'Success 🟢', 'details' => 'No stale cache file existed.'];
}

$hotFile = public_path('hot');
if (File::exists($hotFile)) {
    if (File::delete($hotFile)) {
        $status[] = ['name' => 'Vite Hot File', 'status' => 'Success 🟢', 'details' => 'Stale public/hot removed. System reverted to compiled assets.'];
    } else {
        $status[] = ['name' => 'Vite Hot File', 'status' => 'Failed 🔴', 'details' => 'Could not delete hot file.'];
    }
} else {
    $status[] = ['name' => 'Vite Hot File', 'status' => 'Success 🟢', 'details' => 'No active hot file detected.'];
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تطهير الكاش وتحديث السيرفر | سفارك علينا</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'IBM Plex Sans Arabic', sans-serif;
            background-color: #020810;
            color: #EEF2FF;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .container {
            width: 100%;
            max-width: 750px;
            background: #0A1528;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
        }
        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #D4A843;
            margin-top: 0;
            margin-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 15px;
            text-align: center;
        }
        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            transition: background 0.2s;
        }
        .status-row:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        .status-row:last-child {
            border-bottom: none;
        }
        .name {
            font-weight: 600;
            color: #EEF2FF;
        }
        .badge {
            font-weight: 700;
            font-size: 13px;
        }
        .details {
            font-size: 12px;
            color: #8BA4C8;
            margin-top: 4px;
        }
        .info-col {
            flex: 1;
            padding-left: 15px;
        }
        .btn-home {
            display: block;
            width: 100%;
            text-align: center;
            background: linear-gradient(90deg, #3B82F6, #06B6D4);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-weight: 700;
            text-decoration: none;
            margin-top: 25px;
            transition: opacity 0.2s;
            cursor: pointer;
        }
        .btn-home:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 حالة تحديث السيرفر وتطهير الكاش</h1>
        
        <div>
            <?php foreach ($status as $row): ?>
                <div class="status-row">
                    <div class="info-col">
                        <div class="name"><?php echo htmlspecialchars($row['name']); ?></div>
                        <div class="details"><?php echo htmlspecialchars($row['details']); ?></div>
                    </div>
                    <div class="badge"><?php echo $row['status']; ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <button onclick="window.location.reload();" class="btn-home">🔄 تحديث وإعادة التشغيل الآن</button>
        <a href="/" class="btn-home" style="background: transparent; border: 1px solid rgba(255,255,255,0.1); margin-top: 10px;">🏠 العودة للرئيسية</a>
    </div>
</body>
</html>
