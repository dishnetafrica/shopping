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
