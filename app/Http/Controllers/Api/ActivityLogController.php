<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ActivityLogController extends Controller
{
    #[OA\Get(
        path: '/api/activity-logs',
        tags: ['Activity Log'],
        summary: 'List activity log entries (Admin: all; Manager: own teams only)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'subject_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['task', 'team', 'user'])),
            new OA\Parameter(name: 'subject_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'team_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated activity log list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isTeamMember()) {
            abort(403, 'Team members cannot view the activity log.');
        }

        $query = ActivityLog::query()->with('user:id,name,email')->latest();

        if ($user->isManager()) {
            $teamIds = $user->teams()->pluck('teams.id');
            $query->whereIn('team_id', $teamIds);
        } elseif ($request->filled('team_id')) {
            $query->where('team_id', $request->integer('team_id'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->string('subject_type'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->integer('subject_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }
}
