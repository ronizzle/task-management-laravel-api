<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
    }

    public function test_team_member_cannot_list_users(): void
    {
        $member = User::factory()->create();

        $this->withHeaders($this->authHeaders($member))
            ->getJson('/api/users')
            ->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->create();

        $this->withHeaders($this->authHeaders($admin))
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_manager_cannot_create_admin_user(): void
    {
        $manager = User::factory()->manager()->create();

        $this->withHeaders($this->authHeaders($manager))
            ->postJson('/api/users', [
                'name' => 'New Admin',
                'email' => 'newadmin@test.com',
                'password' => 'password123',
                'role' => 'admin',
            ])
            ->assertStatus(403);
    }

    public function test_manager_can_create_team_member_user(): void
    {
        $manager = User::factory()->manager()->create();

        $this->withHeaders($this->authHeaders($manager))
            ->postJson('/api/users', [
                'name' => 'New Member',
                'email' => 'newmember@test.com',
                'password' => 'password123',
                'role' => 'team_member',
            ])
            ->assertCreated();
    }

    public function test_team_member_cannot_create_task(): void
    {
        $member = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $member->id]);
        $team->members()->attach($member->id, ['role' => 'member']);

        $this->withHeaders($this->authHeaders($member))
            ->postJson("/api/teams/{$team->id}/tasks", ['title' => 'Should fail'])
            ->assertStatus(403);
    }

    public function test_non_creator_cannot_delete_task(): void
    {
        $creator = User::factory()->create();
        $otherMember = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $creator->id]);
        $team->members()->attach([$creator->id, $otherMember->id], ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $creator->id,
        ]);

        $this->withHeaders($this->authHeaders($otherMember))
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertStatus(403);
    }

    public function test_creator_can_delete_task(): void
    {
        $creator = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $creator->id]);
        $team->members()->attach($creator->id, ['role' => 'member']);
        $task = Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $creator->id,
        ]);

        $this->withHeaders($this->authHeaders($creator))
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertStatus(204);
    }
}
