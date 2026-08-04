<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs one line per API request/response to the dedicated 'api' channel.
 * Never logs the request body — only metadata — so it can't leak passwords,
 * tokens, or comment/task content into storage/logs/api.log.
 */
class LogApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        Log::channel('api')->info('api_request', [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
