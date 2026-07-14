<?php

namespace App\Capability\Contracts;

use App\Models\Tenant;
use App\Models\User;

/**
 * The only thing the Capability Layer knows about "who is asking". Carries the tenant, the user,
 * their role and effective permissions. Built once per request from the authenticated user.
 */
final class CapabilityContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
        public readonly string $role,
        public readonly array $permissions = [],
    ) {}

    /**
     * Per-request report-currency override (?cur=base|original|USD|UGX|SSP), set by the transport
     * layer. Null means "use the tenant's report_currency_mode". Mutable by design: it is request
     * state, not identity, so it lives outside the readonly constructor.
     */
    public ?string $displayOverride = null;

    public static function fromUser(User $user): self
    {
        $menus = method_exists($user, 'effectiveMenus') ? (array) $user->effectiveMenus() : [];
        return new self(
            tenant: $user->tenant,
            user: $user,
            role: (string) ($user->role ?? 'user'),
            permissions: $menus,
        );
    }

    public function tenantId(): int
    {
        return (int) $this->tenant->id;
    }

    /**
     * The tenant's home/base currency for headline totals that aren't tied to a single
     * native record (e.g. process payables). Defaults to UGX — the engine's native default.
     */
    public function baseCurrency(): string
    {
        $cur = method_exists($this->tenant, 'setting') ? $this->tenant->setting('currency') : null;

        return strtoupper((string) ($cur ?: 'UGX'));
    }

    /**
     * The tenant's report currency mode: 'base' (consolidate to base currency, default) or
     * 'original' (preserve each transaction's native currency).
     */
    public function reportMode(): string
    {
        $m = method_exists($this->tenant, 'setting') ? $this->tenant->setting('report_currency_mode') : null;
        $m = strtolower((string) ($m ?: 'base'));

        return $m === 'original' ? 'original' : 'base';
    }

    /**
     * The effective single display currency after applying any request override, for headline
     * totals. Returns null when the effective mode is "original" (no single display currency).
     */
    public function displayCurrency(): ?string
    {
        $ov = strtolower(trim((string) $this->displayOverride));
        if ($ov === 'original') {
            return null;
        }
        if ($ov !== '' && $ov !== 'base') {
            $up = strtoupper($ov);
            if (in_array($up, ['USD', 'UGX', 'SSP'], true)) {
                return $up;
            }
        }
        if ($ov === '' && $this->reportMode() === 'original') {
            return null;
        }

        return $this->baseCurrency();
    }

    public function can(string $permission): bool
    {
        if ($permission === '') {
            return true;
        }
        if (method_exists($this->user, 'isOwnerLike') && $this->user->isOwnerLike()) {
            return true;
        }
        $bare = str_replace('menu:', '', $permission);

        return in_array($permission, $this->permissions, true) || in_array($bare, $this->permissions, true);
    }
}
