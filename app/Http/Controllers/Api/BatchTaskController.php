<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesTasks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\BatchTaskRequest;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\RealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BatchTaskController extends Controller
{
    use AuthorizesTasks;

    /**
     * Fields allowed on a batch 'update' — mirrors UpdateTaskRequest minus
     * assigned_to (that's the dedicated 'assign' action) and status (that's
     * 'status_change', which needs transition validation, not a plain set).
     */
    private const UPDATABLE_FIELDS = ['title', 'description', 'priority', 'due_date'];

    #[OA\Post(
        path: '/api/tasks/batch',
        tags: ['Tasks'],
        summary: 'Bulk update/delete/status-change/assign across multiple tasks',
        description: 'Each task_id is checked against the same role/ownership rules as the single-task endpoints. Partial success: a task a caller can\'t touch is reported as a per-task error, it does not fail the whole batch.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['task_ids', 'action'],
                properties: [
                    new OA\Property(property: 'task_ids', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'action', type: 'string', enum: ['update', 'delete', 'status_change', 'assign']),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'cancelled'], nullable: true),
                    new OA\Property(property: 'assigned_to', type: 'integer', nullable: true),
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
                    new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Per-task results — { results: [{ id, ok, message?, task? }], succeeded, failed }'),
            new OA\Response(response: 422, description: 'Validation error (malformed request itself, not a per-task authorization failure)'),
        ]
    )]
    public function handle(BatchTaskRequest $request): JsonResponse
    {
        $action = $request->validated('action');
        $taskIds = $request->validated('task_ids');

        $tasks = Task::whereIn('id', $taskIds)->get()->keyBy('id');

        $results = collect($taskIds)->map(function (int $taskId) use ($request, $action, $tasks) {
            $task = $tasks->get($taskId);

            if (! $task) {
                return ['id' => $taskId, 'ok' => false, 'message' => 'Task not found.'];
            }

            try {
                return match ($action) {
                    'update' => $this->applyUpdate($request, $task),
                    'delete' => $this->applyDelete($request, $task),
                    'status_change' => $this->applyStatusChange($request, $task),
                    'assign' => $this->applyAssign($request, $task),
                };
            } catch (HttpException $e) {
                return ['id' => $taskId, 'ok' => false, 'message' => $e->getMessage()];
            }
        });

        return response()->json([
            'results' => $results->values(),
            'succeeded' => $results->where('ok', true)->count(),
            'failed' => $results->where('ok', false)->count(),
        ]);
    }

    private function applyUpdate(BatchTaskRequest $request, Task $task): array
    {
        $this->assertCanEdit($request, $task);

        $fields = array_intersect_key($request->validated(), array_flip(self::UPDATABLE_FIELDS));

        if (empty($fields)) {
            return ['id' => $task->id, 'ok' => false, 'message' => 'No updatable fields provided.'];
        }

        $before = $task->only(array_keys($fields));
        $task->update($fields);

        ActivityLogger::record(
            $request->user(),
            'updated',
            $task,
            "Updated task \"{$task->title}\" (batch)",
            $task->team_id,
            ['before' => $before, 'after' => $task->only(array_keys($fields))],
        );

        RealtimeBroadcaster::broadcast("task:{$task->id}", 'task_updated', $task->toArray());
        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_updated', $task->toArray());

        return ['id' => $task->id, 'ok' => true, 'task' => $task];
    }

    private function applyDelete(BatchTaskRequest $request, Task $task): array
    {
        $this->assertCanDelete($request, $task);

        $taskId = $task->id;
        $teamId = $task->team_id;

        ActivityLogger::record(
            $request->user(),
            'deleted',
            $task,
            "Deleted task \"{$task->title}\" (batch)",
            $teamId,
        );

        RealtimeBroadcaster::broadcast("task:{$taskId}", 'task_deleted', ['id' => $taskId]);
        RealtimeBroadcaster::broadcast("team:{$teamId}", 'task_deleted', ['id' => $taskId]);

        $task->delete();

        return ['id' => $taskId, 'ok' => true];
    }

    private function applyStatusChange(BatchTaskRequest $request, Task $task): array
    {
        $this->assertCanChangeStatus($request, $task);

        $newStatus = $request->validated('status');

        if (! $task->canTransitionTo($newStatus)) {
            return [
                'id' => $task->id,
                'ok' => false,
                'message' => "Cannot transition from {$task->status} to {$newStatus}.",
            ];
        }

        $oldStatus = $task->status;
        $task->update(['status' => $newStatus]);

        ActivityLogger::record(
            $request->user(),
            'status_changed',
            $task,
            "Changed task \"{$task->title}\" status from {$oldStatus} to {$newStatus} (batch)",
            $task->team_id,
            ['before' => $oldStatus, 'after' => $newStatus],
        );

        $payload = ['id' => $task->id, 'status' => $newStatus, 'previous_status' => $oldStatus];
        RealtimeBroadcaster::broadcast("task:{$task->id}", 'task_status_changed', $payload);
        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_status_changed', $payload);

        return ['id' => $task->id, 'ok' => true, 'task' => $task];
    }

    private function applyAssign(BatchTaskRequest $request, Task $task): array
    {
        $this->assertCanAssign($request, $task);

        $before = $task->assigned_to;
        $task->update(['assigned_to' => $request->validated('assigned_to')]);

        ActivityLogger::record(
            $request->user(),
            'updated',
            $task,
            "Reassigned task \"{$task->title}\" (batch)",
            $task->team_id,
            ['before' => ['assigned_to' => $before], 'after' => ['assigned_to' => $task->assigned_to]],
        );

        RealtimeBroadcaster::broadcast("task:{$task->id}", 'task_updated', $task->toArray());
        RealtimeBroadcaster::broadcast("team:{$task->team_id}", 'task_updated', $task->toArray());

        return ['id' => $task->id, 'ok' => true, 'task' => $task];
    }
}
