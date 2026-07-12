<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The single, config-driven navigation endpoint (Roadmap Epic 1 / Task E1.1).
 *
 * GET /papi/nav returns the grouped left menu (Product Architecture v2 §1),
 * filtered for THIS user by their role (effectiveMenus) and THIS tenant's
 * enabled modules. Read-only. Not consumed by any UI yet — the sidebar is
 * wired to it in Task E1.2 — so shipping this is a no-op for end users.
 *
 * The menu itself lives in config/navigation.php (one source of truth).
 */
class NavController extends Controller
{
    /**
     * Pure, side-effect-free menu builder — independently testable without HTTP.
     *
     * @param  array       $effectiveMenus  keys from User::effectiveMenus()
     * @param  array       $moduleFlags     tenant modules map, e.g. ['inventory' => false]; unset = enabled
     * @param  array|null  $nav             navigation config; defaults to config('navigation')
     * @param  array       $settings        tenant boolean setting gates, e.g. ['order_system_enabled' => true];
     *                                       an item with a 'setting' key is hidden unless that setting is truthy
     * @return array       groups, each ['key','label','items'=>[['key','label','route','icon'], …]]
     */
    public static function menuFor(array $effectiveMenus, array $moduleFlags = [], ?array $nav = null, array $settings = []): array
    {
        $nav = $nav ?? (array) config('navigation', []);
        $out = [];

        foreach ($nav as $group) {
            // Module gate (R11): a group tied to a module is hidden only when the tenant has
            // explicitly disabled it. Uses the SAME module-flag semantics as the API guard
            // (Access::moduleEnabled) so the menu and the endpoints agree.
            $module = $group['module'] ?? null;
            if ($module !== null && ! \App\Support\Access::moduleEnabled($moduleFlags, $module)) {
                continue;
            }

            // Role/permission gate (R9/R10): keep items whose required menu key
            // is in the user's effective menus. Items with no 'menu' are always shown.
            $items = [];
            foreach (($group['items'] ?? []) as $it) {
                $need = $it['menu'] ?? null;
                if ($need !== null && ! in_array($need, $effectiveMenus, true)) {
                    continue;
                }
                // Setting gate: an item may require a tenant boolean setting (e.g. order_system_enabled).
                // Hidden unless that setting is truthy — mirrors the route's own abort(404) guard so the
                // menu never offers a link that would 404. Absent 'setting' = always shown.
                $gate = $it['setting'] ?? null;
                if ($gate !== null && ! (bool) ($settings[$gate] ?? false)) {
                    continue;
                }
                $entry = [
                    'key'   => (string) ($it['key'] ?? ''),
                    'label' => (string) ($it['label'] ?? ''),
                    'route' => (string) ($it['route'] ?? ''),
                    'icon'  => (string) ($it['icon'] ?? ''),
                ];
                // E5.3 — an item may declare sub-view tabs (e.g. Cashbook → Cash Position / Staff Cash /
                // My Cash). Tabs inherit the item's visibility; they are passed through so the ONE screen
                // can render its tab bar from the same nav source of truth.
                if (! empty($it['tabs']) && is_array($it['tabs'])) {
                    $entry['tabs'] = array_map(static fn ($tab) => [
                        'key'   => (string) ($tab['key'] ?? ''),
                        'label' => (string) ($tab['label'] ?? ''),
                        'route' => (string) ($tab['route'] ?? ''),
                    ], array_values($it['tabs']));
                }
                $items[] = $entry;
            }

            if ($items) {
                $out[] = [
                    'key'   => (string) ($group['key'] ?? ''),
                    'label' => (string) ($group['label'] ?? ''),
                    'items' => $items,
                ];
            }
        }

        return $out;
    }

    /**
     * Screens retired/merged by Product Architecture v2 (§2 + deletion list). They must never
     * reappear as a menu link — deep links still resolve (routes are untouched), but the ONE
     * menu must not offer two ways to the same business process. (Roadmap E1.5)
     */
    public const RETIRED_ROUTES = [
        '/panel/cashbook',       // second cashbook (ledger_entries) — merged into Cashbook
        '/panel/cash-position',  // E5.3 — now a Cashbook tab, not a separate menu link
        '/panel/staff-cash',     // E5.3 — now a Cashbook tab, not a separate menu link
        '/panel/my-cash',        // E5.3 — now a Cashbook tab, not a separate menu link
        '/panel/today',      // a dashboard widget, not a screen
        '/panel/oif',        // retired factory module
        '/panel/indent',     // retired factory module
        '/panel/book-cost',  // duplicate purchase form — merged into Purchases
        '/panel/invoiced',   // hardcoded-FX report — becomes a Reports view
        '/panel/ledger',     // second general ledger
        '/panel/books',      // second general ledger / accounting
    ];

    /**
     * Audit the nav config for duplicate item labels or retired routes. Returns [] when clean.
     * Used by the E1.5 guard test and the selfcheck probe so a future edit can't silently
     * reintroduce the v1 duplicate-menu problem.
     */
    public static function menuIssues(?array $nav = null): array
    {
        $nav = $nav ?? (array) config('navigation', []);
        $issues = [];
        $seen   = [];

        foreach ($nav as $group) {
            foreach (($group['items'] ?? []) as $it) {
                $label = (string) ($it['label'] ?? '');
                $route = (string) ($it['route'] ?? '');
                if ($label !== '') {
                    if (isset($seen[$label])) {
                        $issues[] = 'duplicate label: ' . $label;
                    }
                    $seen[$label] = true;
                }
                if ($route !== '' && in_array($route, self::RETIRED_ROUTES, true)) {
                    $issues[] = 'retired route present: ' . $route;
                }
            }
        }

        return $issues;
    }

    /** GET /papi/nav — the grouped menu for the signed-in user. */
    public function nav(Request $request)
    {
        $u = $request->user();
        if (! $u) {
            return response()->json(['ok' => false], 401);
        }

        $menus = method_exists($u, 'effectiveMenus') ? $u->effectiveMenus() : [];

        // Tenant module flags live in settings as a map, e.g. {"inventory": false}.
        // Absent map / absent key => enabled (non-breaking default).
        $flags = [];
        $t = $u->tenant;
        if ($t) {
            $mods = $t->setting('modules', []);
            if (is_array($mods)) {
                $flags = $mods;
            }
        }

        // Per-item boolean setting gates (e.g. Order-Trading pages behind order_system_enabled).
        // Keeps the menu link in lock-step with the route's own guard so nothing 404s.
        $settings = [
            'order_system_enabled' => $t ? (bool) $t->setting('order_system_enabled', false) : false,
        ];

        // E1.2 flag: the sidebar only renders from this config when the tenant has
        // opted in (nav_v2 = true). Default false => existing menu is untouched.
        $navV2 = $t ? (bool) $t->setting('nav_v2', false) : false;

        return response()->json([
            'ok'       => true,
            'enabled'  => $navV2,
            'modules'  => (object) $flags,   // E1.3 — tenant module flags (e.g. {"restaurant": true}); absent = default
            'name'     => (string) ($u->name ?? ''),
            'role'     => (string) $u->role,
            'is_owner' => method_exists($u, 'isOwnerLike') ? $u->isOwnerLike() : false,
            'groups'   => self::menuFor($menus, $flags, null, $settings),
        ]);
    }
}
