<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaskNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithManager(): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $team = Team::factory()->create(['created_by' => $manager->id]);
        $team->members()->attach($manager->id, ['role' => 'lead']);

        return compact('manager', 'team');
    }

    public function test_creating_a_task_with_an_assignee_dispatches_a_task_assigned_notification(): void
    {
        Http::fake();
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $assignee = User::factory()->create();
        $team->members()->attach($assignee->id, ['role' => 'member']);

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", [
                'title' => 'Ship the feature',
                'assigned_to' => $assignee->id,
            ])
            ->assertCreated();

        $taskId = $response->json('id');

        Http::assertSent(function ($request) use ($taskId, $assignee) {
            return $request->url() === config('services.node.url').'/api/notifications/send'
                && $request['task_id'] === $taskId
                && $request['user_id'] === $assignee->id
                && $request['event_type'] === 'task_assigned'
                && $request->hasHeader('X-Internal-Token', config('services.internal.token'));
        });
    }

    public function test_creating_a_task_without_an_assignee_does_not_dispatch_a_notification(): void
    {
        Http::fake();
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();

        $this->actingAs($manager, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", ['title' => 'Unassigned task'])
            ->assertCreated();

        Http::assertNotSent(fn ($request) => $request->url() === config('services.node.url').'/api/notifications/send');
    }

    public function test_status_transition_dispatches_a_task_status_changed_notification(): void
    {
        Http::fake();
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $manager->id,
            'assigned_to' => $manager->id,
            'status' => Task::STATUS_PENDING,
        ]);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => Task::STATUS_IN_PROGRESS])
            ->assertOk();

        Http::assertSent(function ($request) use ($task, $manager) {
            return $request->url() === config('services.node.url').'/api/notifications/send'
                && $request['task_id'] === $task->id
                && $request['user_id'] === $manager->id
                && $request['event_type'] === 'task_status_changed'
                && $request['details']['from_status'] === Task::STATUS_PENDING
                && $request['details']['to_status'] === Task::STATUS_IN_PROGRESS;
        });
    }

    public function test_a_failed_notification_dispatch_does_not_fail_the_underlying_task_request(): void
    {
        Http::fake(fn () => throw new \Exception('Node unreachable'));
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $assignee = User::factory()->create();
        $team->members()->attach($assignee->id, ['role' => 'member']);

        $this->actingAs($manager, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", [
                'title' => 'Ship the feature',
                'assigned_to' => $assignee->id,
            ])
            ->assertCreated();
    }
}
