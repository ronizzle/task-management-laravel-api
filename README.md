# task-management-laravel-api

Laravel 11 REST API — primary backend for the Task Management & Analytics Platform. Owns the database, business logic, and JWT-based authentication (`tymon/jwt-auth`). The Node service never touches the database directly; it always calls back into this API.

Part of the [task-management](https://github.com/ronizzle/task-management) umbrella project. See that repo's `plan.md` for the full spec.

## Requirements

- PHP 8.2+
- Composer
- PostgreSQL (or MySQL) — set `DB_CONNECTION` accordingly

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

Edit `.env`:
- Set `DB_*` to your local database (a PostgreSQL database named to match `DB_DATABASE` must already exist).
- Set `INTERNAL_SERVICE_TOKEN` to any long random string. This must match the `INTERNAL_SERVICE_TOKEN` used by `task-management-node-services` — it's the shared secret Node's cron jobs present to hit the archive endpoint without a user JWT.
- Set `NODE_SERVICE_URL` to wherever the Node service runs locally (default `http://localhost:3000`).

```bash
php artisan migrate --seed
php artisan serve --port=8000
```

API is now available at `http://localhost:8000/api`.

## Test credentials (from the seeder)

| Email | Password | Role |
|---|---|---|
| `admin@test.com` | `password123` | Admin |
| `manager@test.com` | `password123` | Manager |
| `member@test.com` | `password123` | Team Member |

Seeded teams: Engineering (4 members), Marketing (3 members), Sales (2 members). Seeded tasks: Setup database, Write API docs, Fix login bug, Design dashboard.

## Auth

JWT issued via `tymon/jwt-auth`. Send `Authorization: Bearer <token>` on all protected routes. Tokens are obtained from `POST /api/login` or `POST /api/register`.

Node-to-Laravel calls use two patterns:
- **User-triggered** (e.g. Node's analytics endpoint reading task data for a logged-in user): Node forwards the same JWT it received, so Laravel's normal role-based authorization applies.
- **Cron-triggered** (no user in context, e.g. nightly cleanup): Node sends the shared secret as an `X-Internal-Token` header. See `EnsureInternalServiceToken` / `EnsureInternalOrJwt` middleware.

## Roles

| Role | Can do |
|---|---|
| Admin | manage users, full task CRUD (any team), assign to anyone, view all analytics, manage team members |
| Manager | task CRUD within own team, assign within team, view own team's analytics, manage own team's members, cannot manage users/roles |
| Team Member | view/edit only own assigned tasks, cannot delete, assign, or view analytics |

## API surface

**Auth**: `POST /api/register`, `POST /api/login`

**Users**: `GET /api/users`, `POST /api/users`, `GET /api/users/{id}`, `PATCH /api/users/{id}`, `PATCH /api/users/{id}/status`

**Teams**: `GET /api/teams`, `POST /api/teams`, `GET /api/teams/{id}`, `POST /api/teams/{id}/members`, `DELETE /api/teams/{id}/members/{user_id}`

**Tasks**: `GET /api/teams/{team_id}/tasks`, `POST /api/teams/{team_id}/tasks`, `GET /api/tasks/{id}`, `PATCH /api/tasks/{id}`, `DELETE /api/tasks/{id}`, `PATCH /api/tasks/{id}/status`, `DELETE /api/tasks/{id}/archive`

Task status transitions: `pending → {in_progress, cancelled}`, `in_progress → {completed, pending}`, `completed`/`cancelled` are terminal. An invalid transition returns `422`.

**Comments** (bonus): `GET /api/tasks/{task}/comments`, `POST /api/tasks/{task}/comments`, `DELETE /api/comments/{comment}`. Same team/task-access rules as the task itself (Team Members: only tasks assigned to them); deleting is restricted to the comment's author or an Admin.

**Activity log** (bonus): `GET /api/activity-logs` — Admin/Manager only (Manager scoped to own teams, Team Member forbidden). Filters: `subject_type` (`task`/`team`/`user`), `subject_id`, `team_id` (Admin only). See "Activity log" below.

## Rate limiting

All `/api/*` routes are throttled via Laravel's built-in `throttle` middleware, keyed per-user (JWT) or per-IP for unauthenticated requests:
- `/api/register` and `/api/login` — **5 requests/minute** (brute-force/credential-stuffing protection on the only unauthenticated routes).
- Everything else — **60 requests/minute**.

A `429 Too Many Requests` is returned once the limit is hit, with `X-RateLimit-*` headers on every response.

## Request/response logging (bonus)

Every `/api/*` request is logged (one line per request) to a dedicated `api` log channel — `storage/logs/api-{date}.log`, daily-rotated, kept 14 days. Logged: method, path, response status, duration in ms, authenticated user id (`null` if unauthenticated), and IP. The request/response body is never logged, so passwords, tokens, and comment/task content can't leak into the log file.

## Activity log (bonus)

An audit trail of who did what, when, across tasks/teams/users — a dedicated `activity_logs` table (`user_id`, `team_id`, `action`, `subject_type`, `subject_id`, `description`, `changes` JSON, timestamps), written via the `App\Services\ActivityLogger` helper called from the relevant controller actions after each write:
- Tasks: created, updated (before/after diff of changed fields), status_changed (before/after status), deleted, archived.
- Teams: created, member_added, member_removed.
- Users: created, updated (before/after diff), status_changed (active/inactive toggle).

`subject_type` stores a short alias (`task`/`team`/`user`) via an Eloquent morph map rather than the full class name. `GET /api/activity-logs` returns entries newest-first; Admins see everything, Managers see only entries scoped to their own teams (`team_id`), Team Members get a `403`. Covered by `tests/Feature/ActivityLogTest.php` (7 tests: task-create/status-change/delete logging with correct fields and diffs, admin sees all, manager scoped to own teams, team member forbidden, unauthenticated rejected).

## Real-time updates (Socket.IO, bonus)

After every relevant task/comment write (create, update, status change, delete, archive), `App\Services\RealtimeBroadcaster::broadcast()` fires a best-effort `POST` to Node's `/api/realtime/broadcast` (internal-token protected), which relays it to connected Socket.IO clients. A failed/unreachable call is caught and logged — it never fails the underlying request. See `task-management-node-services`' README for the client-facing side (auth handshake, rooms, event names). Covered by `tests/Feature/RealtimeBroadcastTest.php` (4 tests, using `Http::fake()`: task-create broadcasts to the team room, status-change broadcasts to both task and team rooms, comment-create broadcasts to the task room, and a failed broadcast doesn't fail the task request).

## API docs (Swagger/OpenAPI)

Generated via `darkaonline/l5-swagger` from PHP attributes on the controllers.

```bash
php artisan l5-swagger:generate
```

View at `http://localhost:8000/api/documentation`. The generated spec (`storage/api-docs`) is gitignored and regenerated on demand rather than committed.

## Tests

```bash
php artisan test
```

47 feature tests covering auth (register/login/deactivated-account/unauthenticated), role authorization (admin/manager/team_member boundaries), task status transition validation, the internal-service-token guard, task comments (list/create/delete, team/task-access boundaries, author-or-admin delete rule, validation), rate limiting (login/register throttle, general API throttle, `Retry-After` header), request/response logging (status/user/duration captured, body never logged), the activity log (write-side logging on task/team/user actions, admin-vs-manager visibility scoping, team member forbidden), and real-time broadcasting (correct room/event on task/comment writes, failure isolation). Tests run against an in-memory SQLite database (configured in `phpunit.xml`), independent of your local dev database.

## Port

Runs on **8000** locally (`php artisan serve --port=8000`).

## Deployment

Live on Render: **https://task-management-laravel-api-jryf.onrender.com/api**

- Render Web Service, Docker runtime (`Dockerfile` in repo root — Render has no native PHP buildpack). Base image is `php:8.4-cli-alpine`; `composer.lock` resolved several `symfony/*` transitive deps requiring PHP ≥8.4.1, so the image tracks that rather than the `^8.2` floor in `composer.json`.
- Start command runs `php artisan migrate --force && php artisan config:cache` before serving, so schema changes apply automatically on every deploy.
- DB is Render managed Postgres (`task-management-postgre-db`). All secrets (`APP_KEY`, `DB_*`, `JWT_SECRET`, `INTERNAL_SERVICE_TOKEN`, etc.) are set in Render's dashboard, never in the repo.
- Seeded once manually after the first successful migration (`DatabaseSeeder` isn't idempotent — don't re-run it against prod).
- Health check: `GET /up`.
