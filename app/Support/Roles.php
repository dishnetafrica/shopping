<?php declare(strict_types=1);

namespace App\Support;

/**
 * Canonical staff roles (Constitution v1.0 · P0 Security).
 *
 * Role has been a free-string column with no single source of truth — the menu logic, Access::MATRIX,
 * ~30 controllers, and the staff-editor sanitizer all referenced DIFFERENT sets (and 'accounts' vs
 * 'accountant', 'purchaser' vs 'procurement' diverged). This enum is that source of truth. normalize()
 * folds the legacy aliases so every layer agrees; use it wherever a role string is read or saved.
 *
 * Additive and safe: nothing calls this yet. Wire it into User role reads / the staff editor in Phase 0.
 */
final class Roles
{
    public const OWNER         = 'owner';
    public const MANAGER       = 'manager';
    public const ACCOUNTANT    = 'accountant';
    public const CASHIER       = 'cashier';
    public const SALES         = 'sales';
    public const FIELD         = 'field';
    public const PROCUREMENT   = 'procurement';
    public const WAREHOUSE     = 'warehouse';
    public const TRANSPORT     = 'transport';
    public const MARKETING     = 'marketing';
    public const PRODUCT_STAFF = 'product_staff';
    public const STAFF         = 'staff';

    /** The canonical set a role may be stored as. */
    public const ALL = [
        self::OWNER, self::MANAGER, self::ACCOUNTANT, self::CASHIER, self::SALES, self::FIELD,
        self::PROCUREMENT, self::WAREHOUSE, self::TRANSPORT, self::MARKETING, self::PRODUCT_STAFF, self::STAFF,
    ];

    /** Legacy / divergent strings folded onto their canonical role. */
    public const ALIASES = [
        'admin'     => self::OWNER,        // isOwnerLike already treats admin as owner
        'accounts'  => self::ACCOUNTANT,
        'purchaser' => self::PROCUREMENT,
    ];

    /** Fold any input to a canonical role; anything unknown becomes the least-privileged STAFF. */
    public static function normalize(?string $role): string
    {
        $r = strtolower(trim((string) $role));
        $r = self::ALIASES[$r] ?? $r;
        return in_array($r, self::ALL, true) ? $r : self::STAFF;
    }

    public static function isKnown(?string $role): bool
    {
        $r = strtolower(trim((string) $role));
        return in_array(self::ALIASES[$r] ?? $r, self::ALL, true);
    }

    /** For the staff-editor dropdown (owner is assigned via ownership, not this list). */
    public static function assignable(): array
    {
        return array_values(array_filter(self::ALL, fn ($r) => $r !== self::OWNER));
    }
}
