<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => Task::STATUS_PENDING,
            'priority' => fake()->randomElement(['low', 'medium', 'high']),
            'assigned_to' => User::factory(),
            'created_by' => User::factory(),
            'team_id' => Team::factory(),
            'due_date' => fake()->dateTimeBetween('now', '+30 days'),
        ];
    }
}
