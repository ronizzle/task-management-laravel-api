<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('password123'),
        ]);

        $manager = User::factory()->manager()->create([
            'name' => 'Manager User',
            'email' => 'manager@test.com',
            'password' => bcrypt('password123'),
        ]);

        $member = User::factory()->create([
            'name' => 'Team Member User',
            'email' => 'member@test.com',
            'password' => bcrypt('password123'),
        ]);

        $engineering = Team::factory()->create([
            'name' => 'Engineering',
            'created_by' => $admin->id,
        ]);
        $marketing = Team::factory()->create([
            'name' => 'Marketing',
            'created_by' => $admin->id,
        ]);
        $sales = Team::factory()->create([
            'name' => 'Sales',
            'created_by' => $admin->id,
        ]);

        // Engineering (4 members): manager (lead), member, + 2 more
        $engineeringExtras = User::factory()->count(2)->create();
        $engineering->members()->attach($manager->id, ['role' => 'lead']);
        $engineering->members()->attach($member->id, ['role' => 'member']);
        $engineering->members()->attach($engineeringExtras->pluck('id'), ['role' => 'member']);

        // Marketing (3 members)
        $marketingMembers = User::factory()->count(3)->create();
        $marketing->members()->attach($marketingMembers->first()->id, ['role' => 'lead']);
        $marketing->members()->attach($marketingMembers->slice(1)->pluck('id'), ['role' => 'member']);

        // Sales (2 members)
        $salesMembers = User::factory()->count(2)->create();
        $salesMembers->first()->update(['role' => User::ROLE_MANAGER]);
        $sales->members()->attach($salesMembers->first()->id, ['role' => 'lead']);
        $sales->members()->attach($salesMembers->last()->id, ['role' => 'member']);

        Task::factory()->create([
            'title' => 'Setup database',
            'description' => 'Provision and configure the primary database for the platform.',
            'status' => Task::STATUS_COMPLETED,
            'priority' => 'high',
            'assigned_to' => $manager->id,
            'created_by' => $admin->id,
            'team_id' => $engineering->id,
            'due_date' => now()->subDays(3),
        ]);

        Task::factory()->create([
            'title' => 'Write API docs',
            'description' => 'Document all REST endpoints for the Laravel and Node APIs.',
            'status' => Task::STATUS_IN_PROGRESS,
            'priority' => 'medium',
            'assigned_to' => $member->id,
            'created_by' => $manager->id,
            'team_id' => $engineering->id,
            'due_date' => now()->addDays(5),
        ]);

        Task::factory()->create([
            'title' => 'Fix login bug',
            'description' => 'Users are occasionally logged out unexpectedly after token refresh.',
            'status' => Task::STATUS_PENDING,
            'priority' => 'high',
            'assigned_to' => $member->id,
            'created_by' => $manager->id,
            'team_id' => $engineering->id,
            'due_date' => now()->addDays(2),
        ]);

        Task::factory()->create([
            'title' => 'Design dashboard',
            'description' => 'Create the analytics dashboard mockups for the React frontend.',
            'status' => Task::STATUS_PENDING,
            'priority' => 'medium',
            'assigned_to' => $marketingMembers->first()->id,
            'created_by' => $admin->id,
            'team_id' => $marketing->id,
            'due_date' => now()->addDays(10),
        ]);
    }
}
