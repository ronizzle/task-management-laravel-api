<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Allows a route to be hit either by an authenticated user (JWT, e.g. the
 * task creator archiving their own task) or by Node's cron jobs presenting
 * the shared X-Internal-Token header (no user in context).
 */
class EnsureInternalOrJwt
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Internal-Token');
        $expected = config('services.internal.token');

        if ($token && $expected && hash_equals($expected, $token)) {
            $request->attributes->set('is_internal_service', true);

            return $next($request);
        }

        JWTAuth::parseToken()->authenticate();

        return $next($request);
    }
}
