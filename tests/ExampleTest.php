<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page is not a public page in VERO: a visitor who is not logged in
     * is sent on to the login screen (the framework's default "200 OK" example
     * test does not apply to this app).
     */
    public function test_guests_are_redirected_from_the_home_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect();
    }
}
