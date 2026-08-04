<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class InternalServiceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_endpoint_rejects_requests_with_no_credentials(): void
    {
        $task = $this->makeTask();

        $this->deleteJson("/api/tasks/{$task->id}/archive")->assertStatus(401);
    }

    public function test_archive_endpoint_rejects_invalid_internal_token(): void
    {
        $task = $this->makeTask();

        $this->withHeaders(['X-Internal-Token' => 'wrong-token'])
            ->deleteJson("/api/tasks/{$task->id}/archive")
            ->assertStatus(401);
    }

    public function test_archive_endpoint_accepts_valid_internal_token(): void
    {
        $task = $this->makeTask();

        $this->withHeaders(['X-Internal-Token' => config('services.internal.token')])
            ->deleteJson("/api/tasks/{$task->id}/archive")
            ->assertOk();

        $this->assertNotNull($task->fresh()->archived_at);
    }

    public function test_archive_endpoint_accepts_task_creator_jwt(): void
    {
        $creator = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $creator->id]);
        $team->members()->attach($creator->id, ['role' => 'lead']);
        $task = Task::factory()->create(['team_id' => $team->id, 'created_by' => $creator->id]);
        $token = JWTAuth::fromUser($creator);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/tasks/{$task->id}/archive")
            ->assertOk();
    }

    private function makeTask(): Task
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $user->id]);

        return Task::factory()->create(['team_id' => $team->id, 'created_by' => $user->id]);
    }
}
