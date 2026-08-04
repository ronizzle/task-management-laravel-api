<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_transition_from_pending_to_in_progress_succeeds(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'status' => Task::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user, 'api')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => Task::STATUS_IN_PROGRESS]);

        $response->assertOk()->assertJsonPath('status', Task::STATUS_IN_PROGRESS);
    }

    public function test_invalid_transition_from_pending_to_completed_returns_422(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'status' => Task::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user, 'api')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => Task::STATUS_COMPLETED]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => Task::STATUS_PENDING]);
    }

    public function test_transition_from_terminal_status_is_rejected(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'status' => Task::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($user, 'api')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => Task::STATUS_IN_PROGRESS]);

        $response->assertStatus(422);
    }
}
