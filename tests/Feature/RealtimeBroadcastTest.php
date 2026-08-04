<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithManager(): array
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $team = Team::factory()->create(['created_by' => $manager->id]);
        $team->members()->attach($manager->id, ['role' => 'lead']);

        return compact('manager', 'team');
    }

    public function test_creating_a_task_broadcasts_task_created_to_the_team_room(): void
    {
        Http::fake();
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();

        $this->actingAs($manager, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", ['title' => 'Ship the feature'])
            ->assertCreated();

        Http::assertSent(function ($request) use ($team) {
            return $request->url() === config('services.node.url').'/api/realtime/broadcast'
                && $request['room'] === "team:{$team->id}"
                && $request['event'] === 'task_created'
                && $request->hasHeader('X-Internal-Token', config('services.internal.token'));
        });
    }

    public function test_status_transition_broadcasts_task_status_changed_to_the_task_and_team_rooms(): void
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

        Http::assertSent(fn ($request) => $request['room'] === "task:{$task->id}" && $request['event'] === 'task_status_changed');
        Http::assertSent(fn ($request) => $request['room'] === "team:{$team->id}" && $request['event'] === 'task_status_changed');
    }

    public function test_posting_a_comment_broadcasts_comment_created_to_the_task_room(): void
    {
        Http::fake();
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $manager->id,
            'assigned_to' => $manager->id,
        ]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Looks good.'])
            ->assertCreated();

        Http::assertSent(fn ($request) => $request['room'] === "task:{$task->id}" && $request['event'] === 'comment_created');
    }

    public function test_a_failed_broadcast_does_not_fail_the_underlying_task_request(): void
    {
        Http::fake(fn () => throw new \Exception('Node unreachable'));
        ['manager' => $manager, 'team' => $team] = $this->makeTeamWithManager();

        $this->actingAs($manager, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", ['title' => 'Ship the feature'])
            ->assertCreated();
    }
}
