<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\User;

/**
 * Team & Roles — the V3 face of the legacy /panel/staff screen (V3 Migration). Lists staff with their
 * roles, and lets an owner add a member, change a member's role, or remove one — every write posts to
 * the EXISTING guarded /papi/staff endpoints (owner-only, can't remove yourself or the last login, plan
 * seat limits enforced). It also shows a read-only guide to what each role is for. No new access-control
 * code is written here. The strict-enforcement switch (perm_enforce) is deliberately left untouched.
 */
final class TeamCapability implements ProcessCapability
{
    public function key(): string { return 'team'; }

    public function permission(): string { return 'menu:staff'; }

    /** Assignable roles (mirrors PanelApiController::normStaffRole) → friendly label + what it's for. */
    public const ROLES = [
        'owner'         => ['Owner', 'Full access to everything, including team, settings and billing.'],
        'manager'       => ['Manager', 'Runs day-to-day: orders, stock, money and approvals.'],
        'sales'         => ['Sales', 'Sales, customers, quotations and orders.'],
        'product_staff' => ['Product staff', 'Products, categories and stock.'],
        'purchaser'     => ['Purchaser', 'Purchasing, suppliers and the buy list.'],
        'field'         => ['Field / technician', 'The field app — jobs, installs and deliveries.'],
        'transport'     => ['Transport', 'Transport and logistics register.'],
        'staff'         => ['Staff', 'General, limited access.'],
    ];

    private function roleLabel(string $role): string
    {
        return self::ROLES[$role][0] ?? ucfirst($role);
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant;
        $meId = (int) ($ctx->user->id ?? 0);

        $staff = User::where('tenant_id', $ctx->tenantId())->orderBy('id')->get();
        $used = $staff->count();
        $owners = $staff->filter(fn ($u) => in_array((string) $u->role, ['owner', 'admin'], true))->count();
        $cap = $t->userCap();
        $capLabel = $cap === null ? 'Unlimited' : ($used . ' / ' . $cap);

        // Staff list as an overview-card table (read-only; edits are the actions below).
        $rows = [];
        $memberCombo = [];
        foreach ($staff as $u) {
            $isSelf = $meId > 0 && (int) $u->id === $meId;
            $signIn = ((string) ($u->phone ?? '') !== '') ? ('WhatsApp · ' . $u->phone)
                : ($u->password ? 'Password' : ((string) $u->email));
            $rows[] = ['cells' => [
                (string) $u->name . ($isSelf ? ' (you)' : ''),
                $this->roleLabel((string) ($u->role ?: 'staff')),
                $signIn,
            ], 'link' => null];
            if (! $isSelf) {
                $memberCombo[] = $u->id . ' — ' . $u->name . ' (' . $this->roleLabel((string) ($u->role ?: 'staff')) . ')';
            }
        }

        $staffCard = ['kind' => 'table', 'title' => 'Team members', 'sub' => $used . ' ' . ($used === 1 ? 'person' : 'people'),
            'columns' => [['label' => 'Name'], ['label' => 'Role'], ['label' => 'Sign-in']],
            'rows' => $rows, 'empty' => 'No staff yet'];

        // Read-only role guide.
        $answers = [];
        foreach (self::ROLES as $r) {
            $answers[] = ['q' => $r[0], 'value' => $r[1], 'sub' => 'What each role is for'];
        }

        $roleCombo = 'combo:' . implode('|', array_keys(self::ROLES));

        $actions = [[
            'key' => 'add_staff', 'label' => 'Add staff', 'method' => 'POST', 'endpoint' => '/papi/staff/add',
            'actor' => 'human', 'tone' => 'primary',
            'schema' => ['name' => 'text', 'email' => 'text', 'phone' => 'text', 'password' => 'text', 'role' => $roleCombo],
            'labels' => ['name' => 'Full name', 'email' => 'Email', 'phone' => 'WhatsApp number (for login)',
                'password' => 'Password (optional if WhatsApp set)', 'role' => 'Role'],
        ]];
        if ($memberCombo !== []) {
            $combo = 'combo:' . implode('|', $memberCombo);
            $actions[] = ['key' => 'change_role', 'label' => 'Change a role', 'method' => 'POST', 'endpoint' => '/papi/staff/update',
                'actor' => 'human', 'schema' => ['id' => $combo, 'role' => $roleCombo],
                'labels' => ['id' => 'Team member', 'role' => 'New role']];
            $actions[] = ['key' => 'remove_member', 'label' => 'Remove a member', 'method' => 'POST', 'endpoint' => '/papi/staff/delete',
                'actor' => 'human', 'tone' => 'danger', 'schema' => ['id' => $combo],
                'labels' => ['id' => 'Team member to remove'],
                'confirm' => 'Remove this team member? They lose access immediately.'];
        }

        return (new WorkspacePayload)
            ->object(['type' => 'team', 'title' => 'Team & Roles', 'subtitle' => $used . ' member' . ($used === 1 ? '' : 's') . ' · seats: ' . $capLabel])
            ->headline((string) $used, 'team members', 'ok')
            ->financials([
                ['key' => 'members', 'label' => 'Team members', 'value' => (string) $used],
                ['key' => 'seats', 'label' => 'Seats used', 'value' => $capLabel],
                ['key' => 'owners', 'label' => 'Owners', 'value' => (string) $owners],
            ])
            ->answers($answers)
            ->overviewCards([$staffCard])
            ->tabs([['key' => 'overview', 'label' => 'Team']])
            ->actions($actions)
            ->meta(['capability' => 'team', 'listKey' => 'team', 'layout' => 'record', 'permissions' => ['staff'],
                'note' => 'Adds/changes/removals run on the existing owner-only staff endpoints, with lockout protection.']);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['members' => User::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
