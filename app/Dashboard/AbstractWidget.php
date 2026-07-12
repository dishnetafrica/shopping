<?php declare(strict_types=1);

namespace App\Dashboard;

/**
 * Base for dashboard widgets (Roadmap E10.1). Defaults the type to a single stat and provides the
 * describe() the API uses to advertise a widget without computing it.
 */
abstract class AbstractWidget implements DashboardWidget
{
    public function type(): string
    {
        return 'stat';
    }

    public function describe(): array
    {
        return ['key' => $this->key(), 'title' => $this->title(), 'type' => $this->type()];
    }

    /** True for owner/manager — "everyone" scope; false = scope to the user themselves. */
    protected function isPrivileged($user): bool
    {
        if (! $user) return false;
        $owner = method_exists($user, 'isOwnerLike') ? $user->isOwnerLike() : false;
        return $owner || in_array((string) ($user->role ?? ''), ['manager'], true);
    }
}
