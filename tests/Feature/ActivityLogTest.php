<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /** Routes under EnsureInternalOrJwt parse a real bearer token, so actingAs() alone won't do. */
    private function asJwt(User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.JWTAuth::fromUser($user)]);
    }

    private function makeTeamWithManager(): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $team = Team::factory()->create(['created_by' => $manager->id]);
        $team->members()->attach($manager->id, ['role' => 'lead']);

        return compact('manager', 'team');
    }

    public function test_creating_a_task_records_an_activity_log_entry(): void
    {
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", ['title' => 'Write tests']);

        $response->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $manager->id,
            'team_id' => $team->id,
            'action' => 'created',
            'subject_type' => 'task',
        ]);
    }

    public function test_task_status_transition_records_an_activity_log_entry_with_before_and_after(): void
    {
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $manager->id,
            'assigned_to' => $manager->id,
            'status' => Task::STATUS_PENDING,
        ]);

        $response = $this->actingAs($manager, 'api')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => Task::STATUS_IN_PROGRESS]);

        $response->assertOk();

        $log = \App\Models\ActivityLog::where('subject_type', 'task')
            ->where('subject_id', $task->id)
            ->where('action', 'status_changed')
            ->firstOrFail();

        $this->assertSame(['before' => 'pending', 'after' => 'in_progress'], $log->changes);
    }

    public function test_admin_sees_all_activity_log_entries(): void
    {
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherTeam = Team::factory()->create(['created_by' => $admin->id]);

        \App\Models\ActivityLog::factory()->create(['team_id' => $team->id]);
        \App\Models\ActivityLog::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->asJwt($admin)->getJson('/api/activity-logs');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_manager_only_sees_activity_log_entries_for_their_own_teams(): void
    {
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $otherManager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $otherTeam = Team::factory()->create(['created_by' => $otherManager->id]);
        $otherTeam->members()->attach($otherManager->id, ['role' => 'lead']);

        \App\Models\ActivityLog::factory()->create(['team_id' => $team->id]);
        \App\Models\ActivityLog::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->asJwt($manager)->getJson('/api/activity-logs');

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_team_member_cannot_view_activity_log(): void
    {
        $member = User::factory()->create(['role' => User::ROLE_TEAM_MEMBER]);

        $response = $this->asJwt($member)->getJson('/api/activity-logs');

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/activity-logs');

        $response->assertUnauthorized();
    }

    public function test_deleting_a_task_records_an_activity_log_entry(): void
    {
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager, 'api')->deleteJson("/api/tasks/{$task->id}");

        $response->assertNoContent();

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'action' => 'deleted',
        ]);
    }
}
