<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_member_cannot_list_users(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member, 'api')
            ->getJson('/api/users')
            ->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_manager_cannot_create_admin_user(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
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

        $this->actingAs($manager, 'api')
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

        $this->actingAs($member, 'api')
            ->postJson("/api/teams/{$team->id}/tasks", ['title' => 'Should fail'])
            ->assertStatus(403);
    }

    public function test_only_creator_or_admin_can_delete_task(): void
    {
        $creator = User::factory()->create();
        $otherMember = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $creator->id]);
        $team->members()->attach([$creator->id, $otherMember->id], ['role' => 'member']);
        $task = \App\Models\Task::factory()->create([
            'team_id' => $team->id,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($otherMember, 'api')
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertStatus(403);

        $this->actingAs($creator, 'api')
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertStatus(204);
    }
}
