<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Requests\Task\UpdateTaskStatusRequest;
use App\Models\Task;
use App\Models\Team;
use App\Services\ActivityLogger;
use App\Services\NotificationDispatcher;
use App\Services\RealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class TaskController extends Controller
{
    use AuthorizesTasks;

    #[OA\Get(
        path: '/api/teams/{team}/tasks',
        tags: ['Tasks'],
        summary: 'List tasks for a team (Team Members see only their own)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'team', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'in_progress', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'priority', in: 'query', schema: new OA\Schema(type: 'string', enum: ['low', 'medium', 'high'])),
            new OA\Parameter(name: 'assigned_to', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated task list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        $query = $team->tasks()->whereNull('archived_at');

        if ($request->user()?->isTeamMember()) {
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

    #[OA\Post(
        path: '/api/teams/{team}/tasks',
        tags: ['Tasks'],
        summary: 'Create a task in a team (Admin/Manager; Team Members forbidden)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'team', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['title'], properties: [
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high']),
                new OA\Property(property: 'assigned_to', type: 'integer', nullable: true),
                new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Task created'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
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

        ActivityLogger::record(
            $request->user(),
            'created',
            $task,
            "Created task \"{$task->title}\"",
            $team->id,
        );

        RealtimeBroadcaster::broadcast("team:{$team->id}", 'task_created', $task->toArray());

        if ($task->assigned_to) {
            NotificationDispatcher::dispatch($task, $task->assigned_to, 'task_assigned', [
                'title' => $task->title,
                'priority' => $task->priority,
                'due_date' => $task->due_date,
            ]);
        }

        return response()->json($task, 201);
    }

    #[OA\Get(
        path: '/api/tasks/{task}',
        tags: ['Tasks'],
        summary: 'Get a single task',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Task'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        return response()->json($task);
    }

    #[OA\Patch(
        path: '/api/tasks/{task}',
        tags: ['Tasks'],
        summary: 'Update a task (Team Members: only their own assigned task)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high']),
                new OA\Property(property: 'assigned_to', type: 'integer', nullable: true),
                new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated task'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $this->assertCanEdit($request, $task);

        if (array_key_exists('assigned_to', $request->validated())) {
            $this->assertCanAssign($request, $task);
        }

        $before = $task->only(array_keys($request->validated()));
        $task->update($request->validated());

        ActivityLogger::record(
            $request->user(),
            'updated',
            $task,
            "Updated task \"{$task->title}\"",
            $task->team_id,
            ['before' => $before, 'after' => $task->only(array_keys($request->validated()))],
        );

        RealtimeBroadcaster::broadcast("task:{$task->id}", 'task_updated', $task->toArray());
        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_updated', $task->toArray());

        return response()->json($task);
    }

    #[OA\Delete(
        path: '/api/tasks/{task}',
        tags: ['Tasks'],
        summary: 'Delete a task (creator or Admin only)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->assertCanDelete($request, $task);

        ActivityLogger::record(
            $request->user(),
            'deleted',
            $task,
            "Deleted task \"{$task->title}\"",
            $task->team_id,
        );

        RealtimeBroadcaster::broadcast("task:{$task->id}", 'task_deleted', ['id' => $task->id]);
        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_deleted', ['id' => $task->id]);

        $task->delete();

        return response()->json(null, 204);
    }

    #[OA\Patch(
        path: '/api/tasks/{task}/status',
        tags: ['Tasks'],
        summary: 'Transition a task\'s status',
        description: 'pending → {in_progress, cancelled}; in_progress → {completed, pending}; completed/cancelled are terminal. Invalid transitions return 422.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['status'], properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'cancelled']),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated task'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Invalid status transition'),
        ]
    )]
    public function updateStatus(UpdateTaskStatusRequest $request, Task $task): JsonResponse
    {
        $this->assertCanChangeStatus($request, $task);

        $newStatus = $request->validated('status');

        if (! $task->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from {$task->status} to {$newStatus}."],
            ]);
        }

        $oldStatus = $task->status;
        $task->update(['status' => $newStatus]);

        ActivityLogger::record(
            $request->user(),
            'status_changed',
            $task,
            "Changed task \"{$task->title}\" status from {$oldStatus} to {$newStatus}",
            $task->team_id,
            ['before' => $oldStatus, 'after' => $newStatus],
        );

        $statusPayload = ['id' => $task->id, 'status' => $newStatus, 'previous_status' => $oldStatus];
        RealtimeBroadcaster::broadcast("task:{$task->id}", 'task_status_changed', $statusPayload);
        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_status_changed', $statusPayload);

        if ($task->assigned_to) {
            NotificationDispatcher::dispatch($task, $task->assigned_to, 'task_status_changed', [
                'title' => $task->title,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
            ]);
        }

        return response()->json($task);
    }

    #[OA\Delete(
        path: '/api/tasks/{task}/archive',
        tags: ['Tasks'],
        summary: 'Soft-delete (archive) a task',
        description: 'Reachable by the task creator/an admin (JWT) or by Node\'s cron cleanup job (X-Internal-Token header) — see EnsureInternalOrJwt.',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Archived task'),
            new OA\Response(response: 401, description: 'Missing/invalid credentials'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function archive(Request $request, Task $task): JsonResponse
    {
        if (! $request->attributes->get('is_internal_service')) {
            $isCreator = $task->created_by === $request->user()->id;

            if (! $request->user()->isAdmin() && ! $isCreator) {
                abort(403, 'Only the creator or an admin may archive this task.');
            }
        }

        $task->update(['archived_at' => now()]);

        ActivityLogger::record(
            $request->attributes->get('is_internal_service') ? null : $request->user(),
            'archived',
            $task,
            "Archived task \"{$task->title}\"",
            $task->team_id,
        );

        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_archived', ['id' => $task->id]);

        return response()->json($task);
    }
}
