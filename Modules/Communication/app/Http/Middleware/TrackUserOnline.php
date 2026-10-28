<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refreshes the "user is online" cache key after every authenticated API request.
 * The key is consumed by ConversationResource::resolveOnlineStatus().
 *
 * Cache key: communication:user:online:{userId}
 * TTL      : communication.online_ttl_minutes (default 5 min)
 */
class TrackUserOnline
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user()
            ?? Auth::guard('api')->user()
            ?? Auth::user();

        if (! $user) {
            return;
        }

        $ttlMinutes = max(1, (int) config('communication.online_ttl_minutes', 5));

        Cache::put(
            'communication:user:online:'.(int) $user->id,
            true,
            now()->addMinutes($ttlMinutes),
        );
    }
}
