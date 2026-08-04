<?php

namespace Database\Factories;

use App\Models\TaskFilterPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskFilterPreset>
 */
class TaskFilterPresetFactory extends Factory
{
    protected $model = TaskFilterPreset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'filters' => ['status' => 'pending', 'priority' => 'high'],
        ];
    }
}
