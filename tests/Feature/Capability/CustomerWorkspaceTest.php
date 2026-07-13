<?php

namespace Tests\Feature\Capability;

use App\Capability\Registry\CapabilityRegistry;
use Tests\TestCase;

/** Requires a booted app (composer install + DB). Verifies the whole registry boots and resolves. */
class CustomerWorkspaceTest extends TestCase
{
    private const EXPECTED = [
        'statement', 'customer', 'invoice', 'payment', 'supplier', 'product', 'salesOrder', 'purchaseOrder',
        'collections', 'sales.process', 'purchase.process', 'finance', 'warehouse', 'dispatch', 'worklist', 'briefing',
    ];

    public function test_registry_boots_with_all_core_capabilities(): void
    {
        $registry = app(CapabilityRegistry::class);
        foreach (self::EXPECTED as $key) {
            $this->assertTrue($registry->has($key), "capability {$key} not registered");
        }
        $registry->assertDependencies();
    }

    public function test_customer_manifest_is_well_formed(): void
    {
        $m = app(CapabilityRegistry::class)->manifest('customer');
        $this->assertSame('object', $m->type);
        $this->assertSame('menu:customers', $m->permission);
        $this->assertContains('statement', $m->dependsOn);
        $this->assertSame('/api/v1/workspace/customer/{id}', $m->api);
    }

    public function test_briefing_composes_process_capabilities(): void
    {
        $m = app(CapabilityRegistry::class)->manifest('briefing');
        $this->assertSame('process', $m->type);
        $this->assertContains('collections', $m->dependsOn);
        $this->assertContains('sales.process', $m->dependsOn);
    }
}
