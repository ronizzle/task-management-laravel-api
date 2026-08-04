<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RealtimeBroadcaster
{
    /**
     * Asks Node's Socket.IO layer to push `event`/`payload` to `room`.
     * Best-effort: a failed or slow call must never break the request that
     * triggered it, so errors are caught and logged rather than thrown —
     * a missed live update is far cheaper than a failed task write.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function broadcast(string $room, string $event, array $payload = []): void
    {
        try {
            Http::withHeaders(['X-Internal-Token' => config('services.internal.token')])
                ->timeout(2)
                ->post(config('services.node.url').'/api/realtime/broadcast', [
                    'room' => $room,
                    'event' => $event,
                    'payload' => $payload,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast realtime event', [
                'room' => $room,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
