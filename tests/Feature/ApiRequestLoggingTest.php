<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ApiRequestLoggingTest extends TestCase
{
    use RefreshDatabase;

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = storage_path('logs/api-request-logging-test');
        File::deleteDirectory($this->logDir);
        File::ensureDirectoryExists($this->logDir);

        // The 'daily' driver inserts the current date before the extension
        // (e.g. api-YYYY-MM-DD.log), so resolve the actual written file
        // after each request rather than assuming the configured path.
        config(['logging.channels.api.path' => $this->logDir.'/api.log']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logDir);

        parent::tearDown();
    }

    private function writtenLog(): string
    {
        $files = File::files($this->logDir);
        $this->assertNotEmpty($files, 'Expected the api log channel to have written a file.');

        return File::get((string) $files[0]);
    }

    public function test_authenticated_request_is_logged_with_user_id_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        $token = JWTAuth::fromUser($admin);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/users')
            ->assertOk();

        $log = $this->writtenLog();

        $this->assertStringContainsString('api_request', $log);
        $this->assertStringContainsString('"method":"GET"', $log);
        $this->assertStringContainsString('"path":"/api/users"', $log);
        $this->assertStringContainsString('"status":200', $log);
        $this->assertStringContainsString('"user_id":'.$admin->id, $log);
        $this->assertStringContainsString('"duration_ms":', $log);
    }

    public function test_unauthenticated_request_is_logged_without_user_id(): void
    {
        $this->getJson('/api/users')->assertStatus(401);

        $log = $this->writtenLog();

        $this->assertStringContainsString('"status":401', $log);
        $this->assertStringContainsString('"user_id":null', $log);
    }

    public function test_request_body_is_never_logged(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@test.com',
            'password' => 'super-secret-password',
        ])->assertStatus(422);

        $log = $this->writtenLog();

        $this->assertStringNotContainsString('super-secret-password', $log);
        $this->assertStringNotContainsString('nobody@test.com', $log);
    }
}
