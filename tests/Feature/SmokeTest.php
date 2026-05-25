<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class SmokeTest extends TestCase
{
    public function test_home_page_loads()
    {
        $response = $this->get('/');
        $response->assertStatus(200);
    }

    public function test_database_connects()
    {
        $this->assertTrue(DB::connection()->getPdo() !== null);
    }
}