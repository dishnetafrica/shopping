<?php declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * RBAC deny-by-default enforcement point (Constitution v1.0 · P0 Security).
 *
 * Today authorization is menu-visibility plus ~10 opt-in Access::blocks() calls, so a logged-in staff
 * user can hit most /papi/* mutations directly. This middleware is the missing route-level gate: it
 * resolves the sensitive ACTION a route requires (config/permissions.php) and consults the single
 * Access allow-list — the same one the controllers use, so there is ONE source of truth (Principle Zero).
 *
 * It runs in one GLOBAL mode (config permissions.mode) so it can roll out without risk of lockout:
 *   off     — pure pass-through (DEFAULT). Ships dormant: zero behaviour change.
 *   report  — never blocks; LOGS every request a real RBAC would deny (the data to size enforcement).
 *   enforce — 403s a user whose role is not allowed the route's action. Deny-by-default.
 *
 * Owners/super-admins always pass. An unmapped route passes (report logs it so the catalogue can be
 * finished). Any internal error fails OPEN (never take a request down over the gate) — even in enforce.
 */
class PermissionGate
{
    public function handle(Request $request, Closure $next)
    {
        $mode = (string) config('permissions.mode', 'off');
        if ($mode === 'off') {
            return $next($request);
        }

        try {
            $user = $request->user();

            // Owner / admin / super-admin: everything (mirrors Access::allows()).
            if ($user && method_exists($user, 'isOwnerLike') && $user->isOwnerLike()) {
                return $next($request);
            }

            $action = $this->actionFor($request);
            if ($action === null) {
                if ($mode === 'report') {
                    $this->log($request, $user, '(unmapped)', 'unmapped');
                }
                return $next($request); // no action mapped → not a gated route
            }

            if (Access::allows($user, $action)) {
                return $next($request);
            }

            if ($mode === 'enforce') {
                return response()->json(['ok' => false, 'error' => 'forbidden', 'action' => $action], 403);
            }
            $this->log($request, $user, $action, 'would_deny');
            return $next($request);
        } catch (\Throwable $e) {
            Log::warning('PermissionGate error (failing open)', ['error' => $e->getMessage()]);
            return $next($request);
        }
    }

    /** Resolve the route to a required Access action, or null. Prefers a route NAME, else METHOD + URI pattern. */
    private function actionFor(Request $request): ?string
    {
        $map = (array) config('permissions.routes', []);
        $route = $request->route();
        if (! $route) {
            return null;
        }
        $name = method_exists($route, 'getName') ? $route->getName() : null;
        if ($name && isset($map['name:' . $name])) {
            return $map['name:' . $name];
        }
        // URI pattern (e.g. "papi/suppliers/{id}/pay") — stable and matches parameterised routes.
        $key = $request->method() . ' ' . ltrim($route->uri(), '/');
        return $map[$key] ?? null;
    }

    private function log(Request $request, $user, string $action, string $why): void
    {
        Log::channel(config('permissions.log_channel', 'stack'))->info('rbac.' . $why, [
            'action'  => $action,
            'route'   => $request->method() . ' ' . ltrim($request->path(), '/'),
            'user_id' => $user->id ?? null,
            'role'    => $user->role ?? null,
            'tenant'  => $user->tenant_id ?? null,
        ]);
    }
}
