<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;

/** Role-scoped "what do I do next" — composes process summaries, never queries objects directly. */
final class WorklistCapability implements ProcessCapability
{
    public function __construct(
        private CollectionsCapability $collections,
        private SalesProcessCapability $sales,
        private PurchaseProcessCapability $purchase,
        private WarehouseCapability $warehouse,
        private DispatchCapability $dispatch,
    ) {}

    public function key(): string { return 'worklist'; }

    public function permission(): string { return ''; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $s = $this->summary($ctx);
        $tiles = [];
        foreach ($s['queues'] as $q) {
            $tiles[] = ['rel' => $q['key'], 'type' => 'queue', 'label' => $q['label'], 'count' => $q['count'], 'link' => $q['link']];
        }

        return (new WorkspacePayload)
            ->object(['type' => 'process:worklist', 'title' => 'What needs you', 'status' => $ctx->role])
            ->related($tiles)
            ->meta(['capability' => 'worklist', 'ttl' => 30]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $role = strtolower($scope['role'] ?? $ctx->role);
        $col = $this->collections->summary($ctx);
        $sal = $this->sales->summary($ctx);
        $pur = $this->purchase->summary($ctx);
        $war = $this->warehouse->summary($ctx);
        $dis = $this->dispatch->summary($ctx);

        $all = [
            'collect' => ['key' => 'collect', 'label' => 'Payments to collect', 'count' => $col['overdueCount'], 'link' => '/api/v1/workspace/collections'],
            'invoice' => ['key' => 'invoice', 'label' => 'Orders to invoice', 'count' => $sal['toInvoice'], 'link' => '/api/v1/workspace/sales.process'],
            'pay' => ['key' => 'pay', 'label' => 'Supplier bills', 'count' => $pur['payableCount'], 'link' => '/api/v1/workspace/purchase.process'],
            'reorder' => ['key' => 'reorder', 'label' => 'Stock to reorder', 'count' => $war['reorder'], 'link' => '/api/v1/workspace/warehouse'],
            'dispatch' => ['key' => 'dispatch', 'label' => 'Deliveries', 'count' => $dis['inTransit'], 'link' => '/api/v1/workspace/dispatch'],
        ];

        $byRole = [
            'accounts' => ['collect', 'invoice', 'pay'],
            'warehouse' => ['reorder', 'dispatch', 'invoice'],
            'purchasing' => ['pay', 'reorder'],
            'sales' => ['invoice', 'collect'],
        ];
        $keys = $byRole[$role] ?? ['collect', 'invoice', 'reorder', 'dispatch'];

        return ['role' => $role, 'queues' => array_values(array_map(fn ($k) => $all[$k], $keys))];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
