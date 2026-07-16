<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Confines the "transport" role (bus-park operator) to the Transport Register only.
 *
 * This is a hard lock, not just menu-hiding: a transport user who types any other
 * URL is bounced to /transport (GET) or gets 403 (write). It is a strict no-op for
 * every other role, so existing users are completely unaffected.
 */
class ConfineTransportRole
{
    /** Exact paths a transport user may reach (leading slash, no query). */
    private const ALLOW = [
        '/transport',
        '/papi/transport-get',
        '/papi/transport-sync',
        '/panel/logout',
        '/logout',
        '/app/login',
        '/login',
    ];

    /** Path prefixes a transport user may reach (assets, auth, framework). */
    private const ALLOW_PREFIX = [
        '/panel/shared/', '/build/', '/storage/', '/vendor/', '/livewire/', '/up',
    ];

    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if (! $u || (string) $u->role !== 'transport') {
            return $next($request);            // no-op for every other role
        }

        $path = '/' . ltrim($request->path(), '/');
        if ($path === '/') $path = '/';

        if (in_array($path, self::ALLOW, true)) return $next($request);
        foreach (self::ALLOW_PREFIX as $pre) {
            if (str_starts_with($path, $pre)) return $next($request);
        }

        // Anything else is off-limits for this role.
        if ($request->isMethod('get')) return redirect('/transport');
        return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
    }
}
