<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Requests\Task\UpdateTaskStatusRequest;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(Request $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        $query = $team->tasks()->whereNull('archived_at');

        if ($request->user()->isTeamMember()) {
            $query->where('assigned_to', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->integer('assigned_to'));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(StoreTaskRequest $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        if ($request->user()->isTeamMember()) {
            abort(403, 'Team members cannot create tasks.');
        }

        $task = DB::transaction(function () use ($request, $team) {
            return $team->tasks()->create([
                ...$request->validated(),
                'created_by' => $request->user()->id,
                'status' => Task::STATUS_PENDING,
            ]);
        });

        return response()->json($task, 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        return response()->json($task);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        if ($request->user()->isTeamMember() && $task->assigned_to !== $request->user()->id) {
            abort(403, 'You may only edit tasks assigned to you.');
        }

        $task->update($request->validated());

        return response()->json($task);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $isCreator = $task->created_by === $request->user()->id;

        if (! $request->user()->isAdmin() && ! $isCreator) {
            abort(403, 'Only the creator or an admin may delete this task.');
        }

        $task->delete();

        return response()->json(null, 204);
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        if ($request->user()->isTeamMember() && $task->assigned_to !== $request->user()->id) {
            abort(403, 'You may only update the status of tasks assigned to you.');
        }

        $newStatus = $request->validated('status');

        if (! $task->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from {$task->status} to {$newStatus}."],
            ]);
        }

        $task->update(['status' => $newStatus]);

        return response()->json($task);
    }

    public function archive(Request $request, Task $task): JsonResponse
    {
        if (! $request->attributes->get('is_internal_service')) {
            $isCreator = $task->created_by === $request->user()->id;

            if (! $request->user()->isAdmin() && ! $isCreator) {
                abort(403, 'Only the creator or an admin may archive this task.');
            }
        }

        $task->update(['archived_at' => now()]);

        return response()->json($task);
    }

    private function authorizeTeamAccess(Request $request, Team $team): void
    {
        if ($request->user()->isAdmin()) {
            return;
        }

        $isMember = $team->members()->where('user_id', $request->user()->id)->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this team.');
        }
    }

    private function authorizeTaskAccess(Request $request, Task $task): void
    {
        $this->authorizeTeamAccess($request, $task->team);
    }
}
