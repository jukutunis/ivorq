<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;

class RateLimitTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear rate limiter cache before each test
        RateLimiter::clear('api');
        RateLimiter::clear('auth');
    }

    public function test_api_rate_limiting_allows_normal_requests(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $user     = $this->createPropertyAdmin($property);

        // Make 10 requests, which is under the 60/min limit
        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');
            $response->assertStatus(200);
        }
    }

    public function test_api_rate_limiting_blocks_excessive_requests_with_429(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $user     = $this->createPropertyAdmin($property);

        // The limit is 60. We hit it 60 times, they should all pass.
        for ($i = 0; $i < 60; $i++) {
            $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');
            if ($response->status() === 429) {
                $this->fail("Rate limit triggered too early at iteration $i");
            }
            $response->assertStatus(200);
        }

        // The 61st request should be blocked
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');
        $response->assertStatus(429);
    }

    public function test_auth_rate_limiting_blocks_excessive_login_attempts(): void
    {
        // Auth limit is 5.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/login', [
                'email'    => 'test@example.com',
                'password' => 'wrong',
            ]);
            // Depending on implementation, it might be 422 or 401, but definitely not 429 yet.
            $this->assertNotEquals(429, $response->status());
        }

        // The 6th request should be blocked by throttle:auth
        $response = $this->postJson('/login', [
            'email'    => 'test@example.com',
            'password' => 'wrong',
        ]);
        $response->assertStatus(429);
    }
}
