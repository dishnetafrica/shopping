<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Win World MES is retired from the platform — no tenant uses it. Every Win World route
 * (the /panel/winworld hub, production, indents, planning, dashboard, oif, sales,
 * exceptions, and the papi/ww-* API) is blocked for everyone.
 *
 * The controllers and HTML screens are kept in the repo (not deleted), so restoring it for
 * a specific tenant later is a one-line change: replace the abort() with a tenant-setting
 * check, e.g. `if (! $request->user()?->tenant?->setting('module_winworld', false)) abort(404);`
 */
class EnsureWinworldEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort(404);

        return $next($request); // unreachable; kept for signature clarity
    }
}
