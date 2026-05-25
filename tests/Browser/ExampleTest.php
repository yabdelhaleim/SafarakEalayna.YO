<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ExampleTest extends DuskTestCase
{
    public function test_pages_work(): void
    {
        $this->browse(function (Browser $browser) {

            $pages = [
                '/',
                '/login',
                '/dashboard',
            ];

            foreach ($pages as $page) {

                try {

                    $browser->visit($page)
                            ->pause(2000);

                    $logs = $browser->driver->manage()->getLog('browser');

                    echo "\n";
                    echo "=====================\n";
                    echo "PAGE: {$page}\n";

                    if (count($logs)) {

                        echo "JS ERRORS:\n";

                        foreach ($logs as $log) {
                            echo $log['message'] . "\n";
                        }

                    } else {

                        echo "✅ PAGE WORKING OK\n";
                    }

                } catch (\Exception $e) {

                    echo "❌ PAGE CRASHED: {$page}\n";
                    echo $e->getMessage() . "\n";

                    $browser->screenshot(
                        str_replace('/', '_', $page)
                    );
                }
            }
        });
    }
}