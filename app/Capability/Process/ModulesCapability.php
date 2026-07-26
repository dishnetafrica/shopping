<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Support\Access;

/**
 * Modules — the V3 Module Manager (V3 Migration: replaces legacy /panel/system). Lets an owner turn a
 * tenant's feature modules on or off. Reuses the EXISTING backend: the tenant's `settings.modules` map
 * (semantics: unset = on), the same one the menu and the API guards already read (App\Support\Access).
 * Reading is open to settings users; the toggle writes via /papi/modules/toggle, which is owner-only.
 */
final class ModulesCapability implements ProcessCapability
{
    public function key(): string { return 'modules'; }

    public function permission(): string { return 'menu:settings'; }

    /** key => [label, what it covers]. The modules that gate the V3 menu groups + standard features. */
    public const MODULES = [
        'sales'         => ['Sales', 'Orders, customers, invoices, quotations'],
        'purchases'     => ['Purchases', 'Buy list, suppliers, approvals'],
        'inventory'     => ['Stock', 'Inventory, consignments, deliveries'],
        'finance'       => ['Money', 'Payments, cashbook, expenses'],
        'reports'       => ['Reports', 'Profit & loss and reporting'],
        'whatsapp'      => ['Messaging', 'WhatsApp chats, leads, campaigns'],
        'communication' => ['Communication', 'Email centre'],
        'restaurant'    => ['Restaurant', 'Kitchen board, POS, dine-in'],
    ];

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $on = 0;
        $rows = [];
        foreach (self::MODULES as $key => [$label, $desc]) {
            $enabled = Access::tenantModuleEnabled($ctx->tenant, $key);
            if ($enabled) {
                $on++;
            }
            $btn = $enabled
                ? ['icon' => 'close', 'title' => 'Turn off ' . $label, 'tone' => 'danger',
                    'post' => '/papi/modules/toggle', 'body' => ['module' => $key, 'enabled' => false],
                    'confirm' => 'Turn off ' . $label . '? Its screens disappear from the menu for everyone until you turn it back on.']
                : ['icon' => 'check', 'title' => 'Turn on ' . $label,
                    'post' => '/papi/modules/toggle', 'body' => ['module' => $key, 'enabled' => true]];
            $rows[] = [
                'module' => ['value' => $label, 'sub' => $desc],
                'status' => ['value' => $enabled ? 'On' : 'Off', 'badge' => $enabled ? 'ok' : 'default', 'sort' => $enabled ? 'on' : 'off'],
                'act'    => ['buttons' => [$btn]],
            ];
        }

        $count = count(self::MODULES);

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:modules', 'title' => 'Modules',
                'subtitle' => $on . ' of ' . $count . ' modules on',
            ])
            ->financials([
                ['key' => 'on', 'label' => 'Modules on', 'value' => (string) $on, 'tone' => 'ok', 'foot' => 'visible in the menu'],
                ['key' => 'off', 'label' => 'Modules off', 'value' => (string) ($count - $on), 'foot' => 'hidden for everyone'],
            ])
            ->table(
                [
                    ['key' => 'module', 'label' => 'Module', 'type' => 'strong', 'sortable' => true],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'act', 'label' => '', 'type' => 'action', 'sortable' => false],
                ],
                $rows, [], ['pageSize' => 20])
            ->meta(['capability' => 'modules', 'ttl' => 30, 'permissions' => ['menu:settings'],
                'note' => 'Turning a module off hides its screens for everyone; unset = on.']);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $on = 0;
        foreach (array_keys(self::MODULES) as $key) {
            if (Access::tenantModuleEnabled($ctx->tenant, $key)) {
                $on++;
            }
        }
        return ['on' => $on];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
