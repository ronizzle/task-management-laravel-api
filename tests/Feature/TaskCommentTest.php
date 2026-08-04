<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeamWithTask(string $assigneeRole = User::ROLE_TEAM_MEMBER): array
    {
        $creator = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $team = Team::factory()->create(['created_by' => $creator->id]);
        $assignee = User::factory()->create(['role' => $assigneeRole]);
        $team->members()->attach($creator->id, ['role' => 'lead']);
        $team->members()->attach($assignee->id, ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'assigned_to' => $assignee->id,
            'created_by' => $creator->id,
        ]);

        return compact('creator', 'team', 'assignee', 'task');
    }

    public function test_team_member_can_list_comments_on_their_own_task(): void
    {
        ['task' => $task, 'assignee' => $assignee] = $this->makeTeamWithTask();
        Comment::factory()->count(2)->create(['task_id' => $task->id, 'user_id' => $assignee->id]);

        $response = $this->actingAs($assignee, 'api')->getJson("/api/tasks/{$task->id}/comments");

        $response->assertOk()->assertJsonCount(2);
    }

    public function test_team_member_can_comment_on_their_own_assigned_task(): void
    {
        ['task' => $task, 'assignee' => $assignee] = $this->makeTeamWithTask();

        $response = $this->actingAs($assignee, 'api')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Making progress on this.']);

        $response->assertCreated()
            ->assertJsonPath('body', 'Making progress on this.')
            ->assertJsonPath('user_id', $assignee->id)
            ->assertJsonPath('user.id', $assignee->id);

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'body' => 'Making progress on this.',
        ]);
    }

    public function test_team_member_cannot_comment_on_a_task_not_assigned_to_them(): void
    {
        ['team' => $team, 'creator' => $creator] = $this->makeTeamWithTask();
        $otherMember = User::factory()->create(['role' => User::ROLE_TEAM_MEMBER]);
        $team->members()->attach($otherMember->id, ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'assigned_to' => $creator->id,
            'created_by' => $creator->id,
        ]);

        $response = $this->actingAs($otherMember, 'api')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Not my task.']);

        $response->assertForbidden();
        $this->assertDatabaseCount('task_comments', 0);
    }

    public function test_manager_can_comment_on_any_task_within_their_team(): void
    {
        ['task' => $task, 'creator' => $manager] = $this->makeTeamWithTask();

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Please prioritize this.']);

        $response->assertCreated();
    }

    public function test_user_outside_the_team_cannot_comment(): void
    {
        ['task' => $task] = $this->makeTeamWithTask();
        $outsider = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $response = $this->actingAs($outsider, 'api')
            ->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Sneaking in.']);

        $response->assertForbidden();
    }

    public function test_comment_body_is_required(): void
    {
        ['task' => $task, 'assignee' => $assignee] = $this->makeTeamWithTask();

        $response = $this->actingAs($assignee, 'api')->postJson("/api/tasks/{$task->id}/comments", []);

        $response->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        ['task' => $task] = $this->makeTeamWithTask();

        $response = $this->postJson("/api/tasks/{$task->id}/comments", ['body' => 'Hi']);

        $response->assertUnauthorized();
    }

    public function test_comment_author_can_delete_their_own_comment(): void
    {
        ['task' => $task, 'assignee' => $assignee] = $this->makeTeamWithTask();
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $assignee->id]);

        $response = $this->actingAs($assignee, 'api')->deleteJson("/api/comments/{$comment->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_admin_can_delete_any_comment(): void
    {
        ['task' => $task, 'assignee' => $assignee] = $this->makeTeamWithTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $assignee->id]);

        $response = $this->actingAs($admin, 'api')->deleteJson("/api/comments/{$comment->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
    }

    public function test_non_author_team_member_cannot_delete_someone_elses_comment(): void
    {
        ['task' => $task, 'creator' => $creator, 'assignee' => $assignee] = $this->makeTeamWithTask();
        $comment = Comment::factory()->create(['task_id' => $task->id, 'user_id' => $creator->id]);

        $response = $this->actingAs($assignee, 'api')->deleteJson("/api/comments/{$comment->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('task_comments', ['id' => $comment->id]);
    }
}
