<?php

namespace App\Support;

/**
 * Server-side action gate (Roadmap E2.3, Constitution R9/R10).
 *
 * A single allow-list mapping a sensitive ACTION to the roles permitted to perform it. This is the
 * authoritative check controllers call (wired in E2.4) — the hidden UI button is never the control.
 *
 * Rules:
 *   - Owner / admin (isOwnerLike) may do everything.
 *   - Otherwise the user's role must be listed for that action.
 *   - An empty role list means OWNER-ONLY.
 *   - An UNKNOWN action fails CLOSED (deny) — new sensitive endpoints are denied until listed here.
 *
 * This is a positive allow-list and is independent of the menu (deny-by-default) logic; it does not
 * read perm_strict. Keep this map as the one place action permissions live.
 */
class Access
{
    /**
     * action => the tenant module that owns it (Roadmap E2.5, Constitution R11). If that module is
     * disabled for the tenant, the action is blocked for EVERYONE (owner included). Actions absent
     * from this map are platform-level (staff/settings/modules) and are never module-gated.
     */
    public const MODULES = [
        'payment.record'   => 'finance',
        'payment.void'     => 'finance',
        'supplier.pay'     => 'purchases',
        'invoice.issue'    => 'sales',
        'invoice.void'     => 'sales',
        'creditnote.issue' => 'sales',
        'creditnote.void'  => 'sales',
        'order.delete'     => 'sales',
        'stock.adjust'     => 'inventory',
        'shipment.manage'  => 'inventory',
        'cost.approve'     => 'finance',
    ];

    /** action => roles allowed (besides owner/admin). Empty array = owner-only. */
    public const MATRIX = [
        // Money
        'payment.record'   => ['manager', 'cashier', 'sales', 'accountant'],
        'payment.void'     => ['manager', 'accountant'],
        'supplier.pay'     => ['manager', 'procurement', 'accountant'],
        // Billing documents
        'invoice.issue'    => ['manager', 'sales', 'accountant'],
        'invoice.void'     => ['manager', 'accountant'],
        'creditnote.issue' => ['manager', 'accountant'],
        'creditnote.void'  => ['manager', 'accountant'],
        // Operations
        'order.delete'     => ['manager'],
        'stock.adjust'     => ['manager', 'warehouse'],
        'shipment.manage'  => ['manager', 'warehouse', 'procurement'],
        'cost.approve'     => ['manager'],
        // Administration
        'staff.manage'     => ['manager'],
        'settings.manage'  => [],   // owner-only
        'modules.manage'   => [],   // owner-only
    ];

    /** Whether $user may perform $action. Fails closed on unknown action or missing user. */
    public static function allows($user, string $action): bool
    {
        if (! $user) {
            return false;
        }
        if (method_exists($user, 'isOwnerLike') && $user->isOwnerLike()) {
            return true; // owner / admin: everything
        }
        if (! array_key_exists($action, self::MATRIX)) {
            return false; // unknown action => deny (fail closed)
        }
        return in_array((string) ($user->role ?? ''), self::MATRIX[$action], true);
    }

    /** Convenience negation. */
    public static function denies($user, string $action): bool
    {
        return ! self::allows($user, $action);
    }

    /**
     * Enforcement gate for controllers (E2.4). Returns true — meaning "block this request with a
     * 403" — ONLY when the user's tenant has opted into enforcement (setting perm_enforce = true)
     * AND the user may not perform $action. With the flag off (default) this always returns false,
     * so wiring it into an endpoint changes nothing until a tenant deliberately enables it.
     */
    public static function blocks($user, string $action): bool
    {
        if (! $user) {
            return false; // let the endpoint's own auth handle a missing user
        }
        $tenant = $user->tenant ?? null;
        if (! $tenant) {
            return false; // can't read config => don't block (fail open on missing tenant only)
        }

        // Gate 1 — MODULE (R11): a module the tenant has explicitly disabled blocks its actions for
        // everyone, owner included, regardless of perm_enforce. Modules default ENABLED, so this
        // only bites when a tenant deliberately turns a module off.
        $module = self::MODULES[$action] ?? null;
        if ($module !== null && ! self::tenantModuleEnabled($tenant, $module)) {
            return true;
        }

        // Gate 2 — ROLE: enforced only when the tenant opts into strict permissions. Owner bypasses.
        if (! (bool) $tenant->setting('perm_enforce', false)) {
            return false; // enforcement off => no behaviour change
        }
        return ! self::allows($user, $action);
    }

    /** Module-flag semantics (single definition, used by the menu AND the API): unset = enabled. */
    public static function moduleEnabled(array $flags, string $module): bool
    {
        return ($flags[$module] ?? true) !== false;
    }

    /** Whether $module is enabled for this tenant (reads settings.modules). */
    public static function tenantModuleEnabled($tenant, string $module): bool
    {
        $mods = $tenant ? $tenant->setting('modules', []) : [];
        return self::moduleEnabled(is_array($mods) ? $mods : [], $module);
    }

    /** The roles allowed for an action (excluding the implicit owner/admin). [] = owner-only. */
    public static function roles(string $action): array
    {
        return self::MATRIX[$action] ?? [];
    }

    /** All known action keys (for tests / documentation). */
    public static function actions(): array
    {
        return array_keys(self::MATRIX);
    }
}
