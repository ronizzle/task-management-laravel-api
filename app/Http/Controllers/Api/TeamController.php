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

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Team::query();

        if ($request->user()->isManager()) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

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

    public function show(Request $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        return response()->json($team->load('members'));
    }

    public function addMember(AddTeamMemberRequest $request, Team $team): JsonResponse
    {
        $this->authorizeTeamAccess($request, $team);

        $team->members()->syncWithoutDetaching([
            $request->validated('user_id') => ['role' => $request->validated('role', 'member')],
        ]);

        return response()->json($team->load('members'), 201);
    }

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
