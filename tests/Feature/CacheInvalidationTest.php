<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_taggable_cache_store_never_falls_back_to_global_flush(): void
    {
        $unsupportedStore = new class {};

        Cache::shouldReceive('getStore')
            ->andReturn($unsupportedStore);
        Cache::shouldReceive('flush')->never();

        Customer::factory()->create();

        $this->assertDatabaseCount('customers', 1);
    }
}
