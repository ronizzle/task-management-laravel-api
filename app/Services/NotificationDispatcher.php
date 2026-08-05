<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationDispatcher
{
    /**
     * Asks Node to queue a "task assigned" / "status changed" email via Brevo.
     * Best-effort: a failed or slow call must never break the request that
     * triggered it, so errors are caught and logged rather than thrown —
     * a missed notification is far cheaper than a failed task write.
     *
     * @param  array<string, mixed>  $details
     */
    public static function dispatch(Task $task, int $userId, string $eventType, array $details = []): void
    {
        try {
            Http::withHeaders(['X-Internal-Token' => config('services.internal.token')])
                ->timeout(2)
                ->post(config('services.node.url').'/api/notifications/send', [
                    'task_id' => $task->id,
                    'user_id' => $userId,
                    'event_type' => $eventType,
                    'details' => $details,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch task notification', [
                'task_id' => $task->id,
                'user_id' => $userId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
