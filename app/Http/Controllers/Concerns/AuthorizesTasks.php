<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Task;
use App\Models\Team;
use Illuminate\Http\Request;

/**
 * Shared team/task access + ownership rules used by both TaskController
 * (single-task endpoints) and BatchTaskController (bulk endpoint), so the
 * two never drift out of sync on who's allowed to do what.
 */
trait AuthorizesTasks
{
    protected function authorizeTeamAccess(Request $request, Team $team): void
    {
        if ($request->attributes->get('is_internal_service') || $request->user()->isAdmin()) {
            return;
        }

        $isMember = $team->members()->where('user_id', $request->user()->id)->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this team.');
        }
    }

    protected function authorizeTaskAccess(Request $request, Task $task): void
    {
        $this->authorizeTeamAccess($request, $task->team);
    }

    protected function assertCanEdit(Request $request, Task $task): void
    {
        $this->authorizeTaskAccess($request, $task);

        if ($request->user()->isTeamMember() && $task->assigned_to !== $request->user()->id) {
            abort(403, 'You may only edit tasks assigned to you.');
        }
    }

    protected function assertCanChangeStatus(Request $request, Task $task): void
    {
        $this->authorizeTaskAccess($request, $task);

        if ($request->user()->isTeamMember() && $task->assigned_to !== $request->user()->id) {
            abort(403, 'You may only update the status of tasks assigned to you.');
        }
    }

    protected function assertCanDelete(Request $request, Task $task): void
    {
        $isCreator = $task->created_by === $request->user()->id;

        if (! $request->user()->isAdmin() && ! $isCreator) {
            abort(403, 'Only the creator or an admin may delete this task.');
        }
    }

    protected function assertCanAssign(Request $request, Task $task): void
    {
        $this->authorizeTaskAccess($request, $task);

        if ($request->user()->isTeamMember()) {
            abort(403, 'Team members cannot reassign tasks.');
        }
    }
}
