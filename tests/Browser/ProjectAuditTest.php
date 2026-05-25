<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Illuminate\Support\Facades\Route;

class ProjectAuditTest extends DuskTestCase
{
    public function test_full_project_audit(): void
    {
        $this->browse(function (Browser $browser) {

            // 1) جمع كل routes تلقائيًا
            $routes = collect(Route::getRoutes())
                ->filter(function ($route) {
                    return in_array('GET', $route->methods);
                })
                ->map(function ($route) {
                    return $route->uri();
                })
                ->filter(function ($uri) {
                    // تجاهل system routes
                    return !str_contains($uri, 'sanctum')
                        && !str_contains($uri, '_ignition')
                        && !str_contains($uri, 'api');
                })
                ->unique()
                ->values();

            echo "\n================= PROJECT AUDIT START =================\n";

            foreach ($routes as $route) {

                $url = '/' . trim($route, '/');

                try {

                    $browser->visit($url)->pause(1500);

                    // 2) HTTP status الحقيقي
                    $status = $browser->driver->executeScript("
                        return fetch(window.location.href, {method: 'GET'})
                            .then(r => r.status)
                            .catch(() => 500);
                    ");

                    echo "\n-----------------\n";
                    echo "URL: {$url}\n";
                    echo "STATUS: {$status}\n";

                    // 3) كشف redirects الغلط
                    $currentUrl = $browser->driver->getCurrentURL();

                    if (!str_contains($currentUrl, $route)) {
                        echo "⚠️ REDIRECT DETECTED -> {$currentUrl}\n";
                    }

                    // 4) JS errors
                    $logs = $browser->driver->manage()->getLog('browser');

                    if (count($logs)) {
                        echo "❌ JS ERRORS:\n";
                        foreach ($logs as $log) {
                            echo $log['message'] . "\n";
                        }
                    }

                    // 5) لو الصفحة بايظة
                    if ($status >= 400) {
                        echo "❌ BROKEN PAGE\n";
                        $browser->screenshot(str_replace('/', '_', $route));
                    } else {
                        echo "✅ OK\n";
                    }

                } catch (\Exception $e) {

                    echo "❌ CRASHED ROUTE: {$url}\n";
                    echo $e->getMessage() . "\n";

                    $browser->screenshot(str_replace('/', '_', $route));
                }
            }

            echo "\n================= AUDIT DONE =================\n";
        });
    }
}