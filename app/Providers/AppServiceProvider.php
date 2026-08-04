<?php

namespace App\Providers;

use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keeps activity_logs.subject_type readable ("task") instead of
        // storing the full "App\Models\Task" class name.
        Relation::enforceMorphMap([
            'task' => Task::class,
            'team' => Team::class,
            'user' => User::class,
        ]);
    }
}
