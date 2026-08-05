# Manual QA Checklist — Laravel API

Manual test checklist scoped to this service's surface: auth, users, teams, tasks, and the backend half of the bonus features (comments, activity log, batch ops, filter presets, rate limiting, Swagger docs). Run against the live deployment via the React frontend and/or the Swagger UI. A full cross-service checklist (Node + React flows included) lives in the umbrella `task-management` repo as `MANUAL_QA_CHECKLIST.md`.

## Environment

| Service | URL |
|---|---|
| Frontend (drives most of these checks) | https://task-management-react-frontend-spyn.onrender.com |
| This API | https://task-management-laravel-api-jryf.onrender.com/api |
| Swagger docs | https://task-management-laravel-api-jryf.onrender.com/api/documentation |

**Credentials** (seeded, all `password123`): `admin@test.com` · `manager@test.com` · `member@test.com`

**Note:** free-tier Render spins down when idle — first request can take 30–60s. Hit the URL once and let it wake before timed steps (rate-limit checks).

---

## Auth & login

- [ ] `POST /api/login` with valid creds returns a JWT
- [ ] `POST /api/login` with a wrong password returns a clear 401/422, not a 500
- [ ] `POST /api/register` creates a user and returns a token
- [ ] A request to a protected route with no/invalid JWT returns 401
- [ ] JWT from one role only unlocks that role's authorized routes (spot-check via Swagger's "Authorize")

## User management

- [ ] `GET /api/users` paginates and supports role/status filters
- [ ] `POST /api/users` as Admin can create any role; as Manager can only create `team_member`
- [ ] `PATCH /api/users/{id}` updates name/email/role and persists
- [ ] `PATCH /api/users/{id}/status` toggles active/inactive; an inactive user can no longer log in
- [ ] Team Member gets 403 on `GET /api/users`

## Team management

Seeded teams: Engineering (4), Marketing (3), Sales (2).

- [ ] `GET /api/teams` paginated, Admin/Manager only
- [ ] `GET /api/teams/{id}` returns members; Engineering shows 4
- [ ] `POST /api/teams` creates a team
- [ ] `POST /api/teams/{id}/members` / `DELETE /api/teams/{id}/members/{user_id}` add/remove correctly
- [ ] Manager can only manage members on their own team(s)

## Task CRUD & status transitions

Valid transitions: `pending → {in_progress, cancelled}`, `in_progress → {completed, pending}`; `completed`/`cancelled` are terminal.

- [ ] `GET /api/teams/{team_id}/tasks` supports status/priority/assigned_to filters
- [ ] `POST /api/teams/{team_id}/tasks` creates a task
- [ ] `PATCH /api/tasks/{id}` edits fields and persists
- [ ] `PATCH /api/tasks/{id}/status` — valid transition succeeds; invalid transition returns `422`
- [ ] `DELETE /api/tasks/{id}` — creator or Admin only, others get 403
- [ ] `DELETE /api/tasks/{id}/archive` soft-deletes for cron cleanup
- [ ] Team Member can only see/edit tasks assigned to them, and cannot delete or assign

---

## Bonus features (backend)

### Swagger / OpenAPI

- [ ] `/api/documentation` loads the Swagger UI
- [ ] Endpoints are grouped sensibly (auth, users, teams, tasks, comments, batch, filter-presets, activity-logs) with request/response schemas
- [ ] "Authorize" with a JWT, execute a `GET` endpoint directly from the UI

### API rate limiting

Login/register throttled to 5 req/min; general API routes to 60/min.

- [ ] 6 login attempts in under a minute — 6th returns `429`
- [ ] After ~60s, login succeeds again

### Task comments

- [ ] `GET/POST /api/tasks/{task}/comments` — list and post work for a user with task access
- [ ] `DELETE /api/comments/{comment}` — succeeds for the author or Admin
- [ ] Non-author, non-admin gets 403 deleting someone else's comment
- [ ] Team Member can only comment on tasks assigned to them

### Request/response logging

- [ ] (Needs Render log access) `storage/logs/api-*.log` gets one line per request: method/path/status/duration/user id (or null)/IP, and never a request body/password/token

### Activity log

- [ ] `GET /api/activity-logs` as Admin returns all entries with before/after diffs
- [ ] Same endpoint as Manager returns only entries scoped to their own team(s)
- [ ] Same endpoint as Team Member returns `403`
- [ ] A task create/update/status-change/delete each produces a corresponding entry

### Batch task operations

- [ ] `POST /api/tasks/batch` with `action: status_change` updates all eligible tasks
- [ ] Same for `action: delete` and `action: assign`
- [ ] A batch containing one task the caller can't touch returns partial success — `{ id, ok: false, message }` for that task, not a whole-batch failure
- [ ] Each successful task in the batch also produces an activity-log entry and a realtime broadcast

### Saved filter presets

- [ ] `POST /api/filter-presets` saves a named filter combination (team/status/priority/assignee)
- [ ] `GET /api/filter-presets` returns only the caller's own presets
- [ ] `DELETE /api/filter-presets/{id}` — owner only
- [ ] Presets are not visible across different user accounts

### Socket broadcast trigger (Laravel side)

- [ ] Task create/update/status-change/delete/archive and comment create/delete each call `RealtimeBroadcaster::broadcast()` — verify indirectly by watching the React Task Detail/Tasks List update live in another window (Node owns the actual socket connection)
- [ ] A broadcast failure (e.g. Node temporarily unreachable) does not fail the underlying request — confirm the task write still succeeds even if you can't force this scenario directly
