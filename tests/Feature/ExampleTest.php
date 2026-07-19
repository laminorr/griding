<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The application root redirects (302) rather than returning 200, so assert
     * the actual behaviour instead of the stock Laravel 200 that never held here.
     */
    public function test_the_application_root_redirects(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
    }
}
