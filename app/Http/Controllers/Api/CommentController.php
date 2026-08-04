<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Task;
use App\Services\RealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CommentController extends Controller
{
    #[OA\Get(
        path: '/api/tasks/{task}/comments',
        tags: ['Comments'],
        summary: 'List comments on a task, oldest first',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Comment list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        return response()->json(
            $task->comments()->with('user:id,name,email')->oldest()->get()
        );
    }

    #[OA\Post(
        path: '/api/tasks/{task}/comments',
        tags: ['Comments'],
        summary: 'Add a comment to a task',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['body'], properties: [
                new OA\Property(property: 'body', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Comment created'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function store(StoreCommentRequest $request, Task $task): JsonResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $comment = $task->comments()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        $comment->load('user:id,name,email');

        RealtimeBroadcaster::broadcast("task:{$task->id}", 'comment_created', $comment->toArray());

        return response()->json($comment, 201);
    }

    #[OA\Delete(
        path: '/api/comments/{comment}',
        tags: ['Comments'],
        summary: 'Delete a comment (author or Admin only)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $this->authorizeTaskAccess($request, $comment->task);

        $isAuthor = $comment->user_id === $request->user()->id;

        if (! $request->user()->isAdmin() && ! $isAuthor) {
            abort(403, 'Only the author or an admin may delete this comment.');
        }

        RealtimeBroadcaster::broadcast("task:{$comment->task_id}", 'comment_deleted', ['id' => $comment->id]);

        $comment->delete();

        return response()->json(null, 204);
    }

    private function authorizeTaskAccess(Request $request, Task $task): void
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return;
        }

        $isMember = $task->team->members()->where('user_id', $user->id)->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this task.');
        }

        if ($user->isTeamMember() && $task->assigned_to !== $user->id) {
            abort(403, 'You may only comment on tasks assigned to you.');
        }
    }
}
