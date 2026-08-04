<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_login_is_rate_limited_after_5_attempts_per_minute(): void
    {
        User::factory()->create([
            'email' => 'login@test.com',
            'password' => bcrypt('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'login@test.com',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'login@test.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_register_is_rate_limited_after_5_attempts_per_minute(): void
    {
        // Mismatched confirmation keeps every attempt a 422 so none of them
        // trigger the controller's auto-login — that would swap the
        // throttle key from IP-based to user-based mid-loop and invalidate
        // the count.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [
                'name' => 'Someone',
                'email' => "someone{$i}@test.com",
                'password' => 'password123',
                'password_confirmation' => 'does-not-match',
            ])->assertStatus(422);
        }

        $this->postJson('/api/register', [
            'name' => 'One Too Many',
            'email' => 'onetoomany@test.com',
            'password' => 'password123',
            'password_confirmation' => 'does-not-match',
        ])->assertStatus(429);
    }

    public function test_general_api_routes_are_rate_limited_after_60_requests_per_minute(): void
    {
        $admin = User::factory()->admin()->create();
        $headers = $this->authHeaders($admin);

        for ($i = 0; $i < 60; $i++) {
            $this->withHeaders($headers)->getJson('/api/users')->assertOk();
        }

        $this->withHeaders($headers)->getJson('/api/users')->assertStatus(429);
    }

    public function test_rate_limit_response_includes_retry_after_header(): void
    {
        User::factory()->create([
            'email' => 'login@test.com',
            'password' => bcrypt('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'login@test.com',
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'login@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)->assertHeader('Retry-After');
    }
}
