<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up log file before test
        $logPath = storage_path('logs/api_errors.log');
        if (file_exists($logPath)) {
            unlink($logPath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up log file after test
        $logPath = storage_path('logs/api_errors.log');
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        parent::tearDown();
    }

    public function test_api_500_exception_logs_to_storage_path(): void
    {
        // 1. Register a temporary API route that throws a 500 exception
        Route::get('/api/v1/test-500-error', function () {
            throw new \RuntimeException('Simulated 500 Error for testing log path');
        });

        // 2. Make request to the temporary route (which goes through exception handler)
        $response = $this->getJson('/api/v1/test-500-error');

        // Assert 500 Internal Server Error
        $response->assertStatus(500);

        // 3. Verify the file was created at storage_path('logs/api_errors.log')
        $logPath = storage_path('logs/api_errors.log');
        $this->assertFileExists($logPath);

        // 4. Verify log file content contains the expected exception message
        $content = file_get_contents($logPath);
        $this->assertStringContainsString('CRITICAL API ERROR: Simulated 500 Error for testing log path', $content);
        $this->assertStringContainsString('Exception: RuntimeException', $content);
        $this->assertStringContainsString('Path: '.url('/api/v1/test-500-error'), $content);
    }
}
