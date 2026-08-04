<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    #[OA\Get(
        path: '/api/users',
        tags: ['Users'],
        summary: 'List users (Admin/Manager only; Manager scoped to own teams)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string', enum: ['admin', 'manager', 'team_member'])),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated user list'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status') === 'active');
        }

        // Managers only see members of their own teams.
        if ($request->user()?->isManager()) {
            $teamIds = $request->user()->teams()->pluck('teams.id');
            $query->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $teamIds));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    #[OA\Post(
        path: '/api/users',
        tags: ['Users'],
        summary: 'Create a user (Admin: any role; Manager: team_member only)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'role'],
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'role', type: 'string', enum: ['admin', 'manager', 'team_member']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'User created'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreUserRequest $request): JsonResponse
    {
        $role = $request->validated('role');

        if ($request->user()->isManager() && $role !== User::ROLE_TEAM_MEMBER) {
            abort(403, 'Managers may only create team_member accounts.');
        }

        $user = DB::transaction(function () use ($request, $role) {
            return User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => bcrypt($request->validated('password')),
                'role' => $role,
                'is_active' => true,
            ]);
        });

        return response()->json($user, 201);
    }

    #[OA\Get(
        path: '/api/users/{user}',
        tags: ['Users'],
        summary: 'Get a single user',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'User'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, User $user): JsonResponse
    {
        // Include team memberships when a user views their own record, so
        // the frontend can discover "my teams" without a dedicated
        // endpoint (team_members can't call GET /api/teams).
        if ($request->user()?->id === $user->id) {
            $user->load('teams:id,name');
        }

        return response()->json($user);
    }

    #[OA\Patch(
        path: '/api/users/{user}',
        tags: ['Users'],
        summary: 'Update a user (Admin only)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'role', type: 'string', enum: ['admin', 'manager', 'team_member']),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated user'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($request->user()->isManager() && $request->filled('role') && $request->validated('role') !== User::ROLE_TEAM_MEMBER) {
            abort(403, 'Managers may not assign roles above team_member.');
        }

        $user->update($request->validated());

        return response()->json($user);
    }

    #[OA\Patch(
        path: '/api/users/{user}/status',
        tags: ['Users'],
        summary: 'Toggle a user\'s active status (Admin only, soft-delete pattern)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['is_active'], properties: [
                new OA\Property(property: 'is_active', type: 'boolean'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated user'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user->update(['is_active' => $request->boolean('is_active')]);

        return response()->json($user);
    }
}
