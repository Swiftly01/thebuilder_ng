<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_and_home_are_reachable(): void
    {
        $this->get('/up')->assertOk();
        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('data.lead', null);
        $this->getJson('/api/articles')->assertNotFound();
    }
}
