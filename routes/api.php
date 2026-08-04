<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Reads reachable by either an authenticated user (JWT) or Node's cron jobs
// via the shared INTERNAL_SERVICE_TOKEN header — see EnsureInternalOrJwt.
// EnsureRole treats the internal service as a trusted system actor and lets
// it through regardless of the roles listed.
Route::middleware('internal.or.jwt')->group(function () {
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/teams', [TeamController::class, 'index']);
    });
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::get('/teams/{team}', [TeamController::class, 'show']);
    Route::get('/teams/{team}/tasks', [TaskController::class, 'index']);
    Route::delete('/tasks/{task}/archive', [TaskController::class, 'archive']);
});

// Writes stay strictly user-JWT-only — cron jobs never create/modify data
// other than archiving, which is handled above.
Route::middleware('auth:api')->group(function () {
    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
    });

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('/teams', [TeamController::class, 'store']);
        Route::post('/teams/{team}/members', [TeamController::class, 'addMember']);
        Route::delete('/teams/{team}/members/{user}', [TeamController::class, 'removeMember']);
    });

    Route::post('/teams/{team}/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{task}', [TaskController::class, 'update']);
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy']);
    Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
});

Route::get('/tasks/{task}', [TaskController::class, 'show'])->middleware('auth:api');
