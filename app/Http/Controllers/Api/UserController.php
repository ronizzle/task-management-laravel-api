<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
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
        if ($request->user()->isManager()) {
            $teamIds = $request->user()->teams()->pluck('teams.id');
            $query->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $teamIds));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

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

    public function show(User $user): JsonResponse
    {
        return response()->json($user);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($request->user()->isManager() && $request->filled('role') && $request->validated('role') !== User::ROLE_TEAM_MEMBER) {
            abort(403, 'Managers may not assign roles above team_member.');
        }

        $user->update($request->validated());

        return response()->json($user);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user->update(['is_active' => $request->boolean('is_active')]);

        return response()->json($user);
    }
}
