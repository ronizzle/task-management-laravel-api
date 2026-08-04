<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchTaskTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithManagerAndMember(): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $member = User::factory()->create(['role' => User::ROLE_TEAM_MEMBER]);
        $team = Team::factory()->create(['created_by' => $manager->id]);
        $team->members()->attach($manager->id, ['role' => 'lead']);
        $team->members()->attach($member->id, ['role' => 'member']);

        return compact('manager', 'member', 'team');
    }

    public function test_manager_can_bulk_status_change_tasks_within_their_team(): void
    {
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $tasks = Task::factory()->count(3)->create([
            'team_id' => $team->id,
            'created_by' => $manager->id,
            'assigned_to' => $member->id,
            'status' => Task::STATUS_PENDING,
        ]);

        $response = $this->actingAs($manager, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => $tasks->pluck('id')->all(),
            'action' => 'status_change',
            'status' => Task::STATUS_IN_PROGRESS,
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 3)->assertJsonPath('failed', 0);

        foreach ($tasks as $task) {
            $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => Task::STATUS_IN_PROGRESS]);
        }
    }

    public function test_bulk_status_change_reports_a_per_task_error_for_an_invalid_transition_without_failing_the_batch(): void
    {
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $validTask = Task::factory()->create([
            'team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $member->id, 'status' => Task::STATUS_PENDING,
        ]);
        $terminalTask = Task::factory()->create([
            'team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $member->id, 'status' => Task::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($manager, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [$validTask->id, $terminalTask->id],
            'action' => 'status_change',
            'status' => Task::STATUS_IN_PROGRESS,
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 1);
        $this->assertDatabaseHas('tasks', ['id' => $validTask->id, 'status' => Task::STATUS_IN_PROGRESS]);
        $this->assertDatabaseHas('tasks', ['id' => $terminalTask->id, 'status' => Task::STATUS_COMPLETED]);
    }

    public function test_team_member_can_only_bulk_status_change_their_own_assigned_tasks(): void
    {
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $ownTask = Task::factory()->create([
            'team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $member->id, 'status' => Task::STATUS_PENDING,
        ]);
        $othersTask = Task::factory()->create([
            'team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $manager->id, 'status' => Task::STATUS_PENDING,
        ]);

        $response = $this->actingAs($member, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [$ownTask->id, $othersTask->id],
            'action' => 'status_change',
            'status' => Task::STATUS_IN_PROGRESS,
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 1);
        $this->assertDatabaseHas('tasks', ['id' => $ownTask->id, 'status' => Task::STATUS_IN_PROGRESS]);
        $this->assertDatabaseHas('tasks', ['id' => $othersTask->id, 'status' => Task::STATUS_PENDING]);
    }

    public function test_bulk_delete_only_succeeds_for_tasks_the_caller_created_or_as_admin(): void
    {
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $ownTask = Task::factory()->create(['team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $member->id]);
        $othersTask = Task::factory()->create(['team_id' => $team->id, 'created_by' => $member->id, 'assigned_to' => $member->id]);

        $response = $this->actingAs($manager, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [$ownTask->id, $othersTask->id],
            'action' => 'delete',
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 1);
        $this->assertDatabaseMissing('tasks', ['id' => $ownTask->id]);
        $this->assertDatabaseHas('tasks', ['id' => $othersTask->id]);
    }

    public function test_bulk_update_applies_only_allowed_fields(): void
    {
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $task = Task::factory()->create(['team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $member->id, 'priority' => 'low']);

        $response = $this->actingAs($manager, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [$task->id],
            'action' => 'update',
            'priority' => 'high',
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'priority' => 'high']);
    }

    public function test_bulk_assign_requires_admin_or_manager(): void
    {
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $task = Task::factory()->create(['team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $manager->id]);

        $response = $this->actingAs($member, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [$task->id],
            'action' => 'assign',
            'assigned_to' => $member->id,
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 0)->assertJsonPath('failed', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assigned_to' => $manager->id]);
    }

    public function test_bulk_assign_succeeds_for_a_manager_and_broadcasts_realtime_updates(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        ['manager' => $manager, 'team' => $team, 'member' => $member] = $this->makeTeamWithManagerAndMember();
        $task = Task::factory()->create(['team_id' => $team->id, 'created_by' => $manager->id, 'assigned_to' => $manager->id]);

        $response = $this->actingAs($manager, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [$task->id],
            'action' => 'assign',
            'assigned_to' => $member->id,
        ]);

        $response->assertOk()->assertJsonPath('succeeded', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'assigned_to' => $member->id]);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request['event'] === 'task_updated');
    }

    public function test_task_ids_must_reference_existing_tasks(): void
    {
        ['manager' => $manager] = $this->makeTeamWithManagerAndMember();

        $response = $this->actingAs($manager, 'api')->postJson('/api/tasks/batch', [
            'task_ids' => [999999],
            'action' => 'delete',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('task_ids.0');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/tasks/batch', ['task_ids' => [1], 'action' => 'delete']);

        $response->assertUnauthorized();
    }
}
