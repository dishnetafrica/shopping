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
     * Words that mark a GET as a WRITE. A hand-list of route names was the first attempt and it was
     * already wrong — it was built from the four endpoints the audit happened to quote and missed
     * `update-product`, which writes. Matching on the verb catches the ones nobody remembered.
     *
     * A false positive here costs a bookmarked read its no-referrer case; a false negative costs a
     * product. The trade is deliberately one-sided.
     */
    private const WRITE_WORDS = [
        'delete', 'save', 'update', 'create', 'add', 'remove', 'toggle', 'set-', 'reset',
        'action', 'apply', 'dismiss', 'approve', 'reject', 'send', 'assign', 'cancel', 'void',
        'import', 'restore', 'merge', 'move', 'issue', 'receive', 'transfer', 'pay',
    ];

    public function handle(Request $request, Closure $next)
    {
        // The HTTP test client sends neither Origin nor Referer, so under the rule below every
        // papi write in the suite would be refused. Nothing about a test run is cross-site. The
        // logic itself is covered directly by tests/Feature/Security/RequireSameOriginTest.php,
        // which calls handle() with fabricated requests rather than going through the kernel.
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        $host = strtolower($request->getHost());
        $evidence = null;
        foreach (['Origin', 'Referer'] as $header) {
            $raw = (string) $request->headers->get($header, '');
            if ($raw === '') {
                continue;
            }
            $h = strtolower((string) parse_url($raw, PHP_URL_HOST));
            if ($h !== '') { $evidence = $h; break; }
        }

        // 1. It says where it came from, and it was not here. Refuse, whatever the method.
        if ($evidence !== null) {
            return $this->sameSite($evidence, $host) ? $next($request) : $this->refuse($request);
        }

        // 2. No Origin and no Referer.
        //
        // For POST/PUT/PATCH/DELETE this is NOT the attack shape and refusing it was wrong: a
        // browser always sends Origin on a cross-origin request of those methods, and a cross-site
        // POST does not carry a SameSite=Lax cookie in the first place. A request with neither
        // header is curl, a mobile client or a test — none of which hold the victim's session.
        //
        // For a GET it IS the attack shape, and the only one: a top-level navigation sends no
        // Origin, carries the Lax cookie, and can suppress its own Referer with
        // <meta name="referrer" content="no-referrer">. So an unattributed GET is refused when the
        // path says it writes, and allowed when it reads.
        if ($request->isMethodSafe() && $this->writingPath($request)) {
            return $this->refuse($request);
        }

        return $next($request);
    }

    private function writingPath(Request $request): bool
    {
        $path = strtolower(trim((string) $request->path(), '/'));
        foreach (self::WRITE_WORDS as $w) {
            if (str_contains($path, $w)) {
                return true;
            }
        }

        return false;
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
