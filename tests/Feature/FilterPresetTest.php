<?php

namespace Tests\Feature;

use App\Models\TaskFilterPreset;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_a_filter_preset(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_TEAM_MEMBER]);
        $team = Team::factory()->create(['created_by' => $user->id]);

        $response = $this->actingAs($user, 'api')->postJson('/api/filter-presets', [
            'name' => 'My urgent tasks',
            'filters' => ['team_id' => $team->id, 'status' => 'pending', 'priority' => 'high'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'My urgent tasks')
            ->assertJsonPath('filters.priority', 'high');

        $this->assertDatabaseHas('task_filter_presets', ['user_id' => $user->id, 'name' => 'My urgent tasks']);
    }

    public function test_user_only_sees_their_own_filter_presets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        TaskFilterPreset::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
        TaskFilterPreset::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $response = $this->actingAs($user, 'api')->getJson('/api/filter-presets');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Mine');
    }

    public function test_preset_name_must_be_unique_per_user(): void
    {
        $user = User::factory()->create();
        TaskFilterPreset::factory()->create(['user_id' => $user->id, 'name' => 'Duplicate']);

        $response = $this->actingAs($user, 'api')->postJson('/api/filter-presets', [
            'name' => 'Duplicate',
            'filters' => ['status' => 'pending'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_two_different_users_can_reuse_the_same_preset_name(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        TaskFilterPreset::factory()->create(['user_id' => $other->id, 'name' => 'Shared name']);

        $response = $this->actingAs($user, 'api')->postJson('/api/filter-presets', [
            'name' => 'Shared name',
            'filters' => ['status' => 'pending'],
        ]);

        $response->assertCreated();
    }

    public function test_owner_can_delete_their_own_preset(): void
    {
        $user = User::factory()->create();
        $preset = TaskFilterPreset::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'api')->deleteJson("/api/filter-presets/{$preset->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('task_filter_presets', ['id' => $preset->id]);
    }

    public function test_non_owner_cannot_delete_someone_elses_preset(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $preset = TaskFilterPreset::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user, 'api')->deleteJson("/api/filter-presets/{$preset->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('task_filter_presets', ['id' => $preset->id]);
    }

    public function test_filters_reject_an_invalid_status_value(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'api')->postJson('/api/filter-presets', [
            'name' => 'Bad preset',
            'filters' => ['status' => 'not_a_real_status'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('filters.status');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/filter-presets');

        $response->assertUnauthorized();
    }
}
