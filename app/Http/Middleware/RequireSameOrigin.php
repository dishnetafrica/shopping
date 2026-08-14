<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * v5.05 (launch audit P1-5) — close cross-site requests to the authenticated panel API.
 *
 * ── the hole ────────────────────────────────────────────────────────────────────────────────────
 *
 * `bootstrap/app.php` exempts the whole of `papi/*` from CSRF. That is survivable when the writes
 * are POSTs, because a `SameSite=Lax` cookie is not sent on a cross-site POST. But roughly forty of
 * these endpoints are registered as GET, including:
 *
 *     routes/web.php:687   Route::get('delete-product', …)
 *     routes/web.php:739   Route::get('settings-save',  …)
 *     routes/web.php:655   Route::get('update-status',  …)
 *     routes/web.php:802   Route::get('branch-delete',  …)
 *
 * and a `SameSite=Lax` cookie IS sent on a top-level GET navigation. So any page a signed-in owner
 * visits could run `location = '…/papi/delete-product?id=412'` and it would work. `settings-save` is
 * the worst of them — `claude/SETTINGS-BLANK-SAVE-ERASES-IMAGES.md` records what a blank save does.
 *
 * ── why this, and not the other two fixes ───────────────────────────────────────────────────────
 *
 * Converting forty routes to POST means touching every call site in the panel UI — a large change to
 * a working surface, days before a launch. Setting `SESSION_SAME_SITE=strict` closes it globally but
 * signs the owner out of every link arriving from WhatsApp, which is how this business actually
 * navigates. This is the narrow fix: the panel's own requests always carry an Origin or a Referer
 * from the panel's own host, and a cross-site request either carries someone else's or (with
 * `<meta name="referrer" content="no-referrer">`, which an attacker controls) carries neither.
 *
 * SAFE READS ARE NOT BLOCKED. A GET with no side effect is allowed through even with no headers, so
 * a bookmarked report or a copy-pasted URL still works; the gate applies to POST/PUT/PATCH/DELETE
 * and to the GET endpoints that write, which are named below. Being explicit about that list is the
 * point: a new writing GET is not covered until somebody adds it here, so the list is the contract.
 */
class RequireSameOrigin
{
    /**
     * GET endpoints under papi/ that CHANGE something. Matched against the path segment after
     * `papi/`. Everything else is treated as a read.
     */
    private const WRITING_GETS = [
        'delete-product', 'settings-save', 'update-status', 'rider-delete', 'branch-delete',
        'bot-toggle', 'save-order', 'customer-save', 'lead-save', 'lead-action',
        'activity-approve', 'activity-reject', 'shipment-action', 'zone-delete', 'notif-delete',
        'default-delete', 'research-apply', 'research-dismiss', 'delivery-zone-delete',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (! $this->mutating($request)) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        foreach (['Origin', 'Referer'] as $header) {
            $raw = (string) $request->headers->get($header, '');
            if ($raw === '') {
                continue;
            }
            $h = strtolower((string) parse_url($raw, PHP_URL_HOST));
            if ($h === '') {
                continue;
            }

            return $this->sameSite($h, $host) ? $next($request) : $this->refuse($request);
        }

        // Neither header present on a state-changing request. A browser sends at least one of them
        // for anything the panel itself does; the shape with neither is the attack (a navigation
        // from a page that suppressed its referrer). Refuse.
        return $this->refuse($request);
    }

    private function mutating(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return true;                                  // POST / PUT / PATCH / DELETE
        }
        $path = trim((string) $request->path(), '/');
        $seg  = explode('/', preg_replace('#^papi/#', '', $path))[0] ?? '';

        return in_array($seg, self::WRITING_GETS, true);
    }

    /** Same host, or the same registrable domain (panel and shop on one site). */
    private function sameSite(string $a, string $b): bool
    {
        $a = preg_replace('#^www\.#', '', $a);
        $b = preg_replace('#^www\.#', '', $b);
        if ($a === $b) {
            return true;
        }
        $tail = fn (string $h) => implode('.', array_slice(explode('.', $h), -2));

        return $tail($a) === $tail($b);
    }

    private function refuse(Request $request)
    {
        \Illuminate\Support\Facades\Log::warning('papi.cross_origin_blocked', [
            'path' => $request->path(), 'method' => $request->method(),
            'origin' => $request->headers->get('Origin'), 'referer' => $request->headers->get('Referer'),
            'ip' => $request->ip(), 'user' => optional($request->user())->id,
        ]);

        return response()->json([
            'ok' => false, 'error' => 'cross_origin',
            'message' => 'This request did not come from the panel. Open it from your dashboard and try again.',
        ], 403);
    }
}
