<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\AddTeamMemberRequest;
use App\Http\Requests\Team\StoreTeamRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class TeamController extends Controller
{
    #[OA\Get(
        path: '/api/teams',
        tags: ['Teams'],
        summary: 'List teams (Admin/Manager only; Manager scoped to own teams)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated team list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Team::query();

        if ($request->user()->isManager()) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    #[OA\Post(
        path: '/api/teams',
        tags: ['Teams'],
        summary: 'Create a team (Admin/Manager); creator is added as lead',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['name'], properties: [
                new OA\Property(property: 'name', type: 'string'),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Team created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreTeamRequest $request): JsonResponse
    {
        $team = DB::transaction(function () use ($request) {
            $team = Team::create([
                'name' => $request->validated('name'),
                'created_by' => $request->user()->id,
            ]);

            $team->members()->attach($request->user()->id, ['role' => 'lead']);

            return $team;
        });

        return response()->json($team->load('members'), 201);
    }

    #[OA\Get(
        path: '/api/teams/{team}',
        tags: ['Teams'],
        summary: 'Get a team with its members',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'team', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Team with members'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function show(Request $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        return response()->json($team->load('members'));
    }

    #[OA\Post(
        path: '/api/teams/{team}/members',
        tags: ['Teams'],
        summary: 'Add a member to a team (Admin/Manager)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'team', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['user_id'], properties: [
                new OA\Property(property: 'user_id', type: 'integer'),
                new OA\Property(property: 'role', type: 'string', enum: ['member', 'lead']),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Team with updated members'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function addMember(AddTeamMemberRequest $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        $team->members()->syncWithoutDetaching([
            $request->validated('user_id') => ['role' => $request->validated('role', 'member')],
        ]);

        return response()->json($team->load('members'), 201);
    }

    #[OA\Delete(
        path: '/api/teams/{team}/members/{user}',
        tags: ['Teams'],
        summary: 'Remove a member from a team (Admin/Manager)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'team', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Removed'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function removeMember(Request $request, Team $team, User $user): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        $team->members()->detach($user->id);

        return response()->json(null, 204);
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
}
