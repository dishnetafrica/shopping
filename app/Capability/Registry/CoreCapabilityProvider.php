<?php

namespace App\Capability\Registry;

use App\Capability\Object\CustomerCapability;
use App\Capability\Object\DemandPlanCapability;
use App\Capability\Process\AdsCapability;
use App\Capability\Process\DemandPlanListCapability;
use App\Capability\Process\JubaStockCapability;
use App\Capability\Process\ProcurementDashboardCapability;
use App\Capability\Object\InvoiceCapability;
use App\Capability\Object\PaymentCapability;
use App\Capability\Object\ProductCapability;
use App\Capability\Object\PurchaseOrderCapability;
use App\Capability\Object\QuotationCapability;
use App\Capability\Object\SalesOrderCapability;
use App\Capability\Object\SupplierCapability;
use App\Capability\Process\CampaignListCapability;
use App\Capability\Process\CashbookCapability;
use App\Capability\Process\CategoryListCapability;
use App\Capability\Process\CollectionsCapability;
use App\Capability\Process\CustomerListCapability;
use App\Capability\Process\DispatchCapability;
use App\Capability\Process\InvoiceListCapability;
use App\Capability\Process\LeadListCapability;
use App\Capability\Process\PaymentListCapability;
use App\Capability\Process\ProductListCapability;
use App\Capability\Process\QuotationListCapability;
use App\Capability\Process\FinanceCapability;
use App\Capability\Process\MorningBriefingCapability;
use App\Capability\Process\PurchaseProcessCapability;
use App\Capability\Process\SalesOrderListCapability;
use App\Capability\Process\SalesProcessCapability;
use App\Capability\Process\SettingsCapability;
use App\Capability\Process\SetupCapability;
use App\Capability\Process\SupplierListCapability;
use App\Capability\Process\WarehouseCapability;
use App\Capability\Process\WorklistCapability;
use App\Capability\Shared\AssistantCapability;
use App\Capability\Shared\HealthCapability;
use App\Capability\Shared\MoneyTimingCapability;
use App\Capability\Shared\NavCountsCapability;
use App\Capability\Shared\RecommendationCapability;
use App\Capability\Shared\StatementCapability;
use App\Capability\Shared\StockViewCapability;
use App\Capability\Shared\TimelineCapability;
use App\Services\Invoicing\StatementService;

/** Registers every core capability. Plugins register/decorate elsewhere without editing this file. */
final class CoreCapabilityProvider implements CapabilityProvider
{
    public function register(CapabilityRegistry $r): void
    {
        // --- shared helpers (internal deps; only Statement is itself a resolvable capability) ---
        $statement = new StatementCapability(app(StatementService::class));
        $health = new HealthCapability();
        $timeline = new TimelineCapability();
        $assistant = new AssistantCapability();
        $money = new MoneyTimingCapability();
        $reco = new RecommendationCapability();
        $stock = new StockViewCapability();

        $r->register($statement, new CapabilityManifest(key: 'statement', type: 'shared', owner: 'core', permission: 'menu:finance'));
        $r->register(new NavCountsCapability(), new CapabilityManifest(key: '_nav', type: 'shared', owner: 'core', permission: ''));

        // --- object capabilities ---
        $r->register(new CustomerCapability($statement, $health, $timeline, $assistant), new CapabilityManifest(
            key: 'customer', type: 'object', owner: 'sales', dependsOn: ['statement'],
            events: ['consumes' => ['InvoicePaid', 'PaymentRecorded'], 'emits' => ['WorkspaceViewed']],
            permission: 'menu:customers',
            commands: [['key' => 'record_payment', 'actor' => 'human'], ['key' => 'send_reminder', 'actor' => 'human']],
            api: '/api/v1/workspace/customer/{id}',
        ));
        $r->register(new InvoiceCapability(), new CapabilityManifest(key: 'invoice', type: 'object', owner: 'accounts', permission: 'menu:finance', commands: [['key' => 'send_reminder', 'actor' => 'human']], api: '/api/v1/workspace/invoice/{id}'));
        $r->register(new PaymentCapability(), new CapabilityManifest(key: 'payment', type: 'object', owner: 'accounts', permission: 'menu:finance', api: '/api/v1/workspace/payment/{id}'));
        $r->register(new SupplierCapability(), new CapabilityManifest(key: 'supplier', type: 'object', owner: 'purchasing', permission: 'menu:purchases', api: '/api/v1/workspace/supplier/{id}'));
        $r->register(new ProductCapability($reco), new CapabilityManifest(key: 'product', type: 'object', owner: 'warehouse', permission: 'menu:products', api: '/api/v1/workspace/product/{id}'));
        $r->register(new SalesOrderCapability(), new CapabilityManifest(key: 'salesOrder', type: 'object', owner: 'sales', permission: 'menu:orders', api: '/api/v1/workspace/salesOrder/{id}'));
        $r->register(new PurchaseOrderCapability(), new CapabilityManifest(key: 'purchaseOrder', type: 'object', owner: 'purchasing', permission: 'menu:purchases', api: '/api/v1/workspace/purchaseOrder/{id}'));
        $r->register(new DemandPlanCapability(), new CapabilityManifest(key: 'demandPlan', type: 'object', owner: 'purchasing', permission: 'menu:purchases', commands: [['key' => 'submit', 'actor' => 'human'], ['key' => 'record_procurement', 'actor' => 'human']], api: '/api/v1/workspace/demandPlan/{id}'));
        $r->register(new QuotationCapability(), new CapabilityManifest(key: 'quotation', type: 'object', owner: 'sales', permission: 'menu:orders', api: '/api/v1/workspace/quotation/{id}'));

        // --- process capabilities ---
        $collections = new CollectionsCapability();
        $sales = new SalesProcessCapability();
        $purchase = new PurchaseProcessCapability();
        $warehouse = new WarehouseCapability($stock);
        $dispatch = new DispatchCapability();
        $ads = new AdsCapability();

        $r->register($collections, new CapabilityManifest(key: 'collections', type: 'process', owner: 'accounts', dependsOn: ['statement'], events: ['consumes' => ['PaymentRecorded'], 'emits' => ['RemindersSent']], permission: 'menu:finance', commands: [['key' => 'bulk_remind', 'actor' => 'human']], api: '/api/v1/workspace/collections'));
        $r->register($sales, new CapabilityManifest(key: 'sales.process', type: 'process', owner: 'sales', permission: 'menu:orders', api: '/api/v1/workspace/sales.process'));
        $r->register(new CustomerListCapability(), new CapabilityManifest(key: 'customers', type: 'process', owner: 'sales', permission: 'menu:customers', api: '/api/v1/workspace/customers'));
        $r->register(new LeadListCapability(), new CapabilityManifest(key: 'leads', type: 'process', owner: 'sales', permission: 'menu:customers', api: '/api/v1/workspace/leads'));
        $r->register(new QuotationListCapability(), new CapabilityManifest(key: 'quotations', type: 'process', owner: 'sales', permission: 'menu:orders', api: '/api/v1/workspace/quotations'));
        $r->register(new InvoiceListCapability(), new CapabilityManifest(key: 'invoices', type: 'process', owner: 'accounts', permission: 'menu:finance', api: '/api/v1/workspace/invoices'));
        $r->register(new SalesOrderListCapability(), new CapabilityManifest(key: 'salesOrders', type: 'process', owner: 'sales', permission: 'menu:orders', api: '/api/v1/workspace/salesOrders'));
        $r->register(new PaymentListCapability(), new CapabilityManifest(key: 'payments', type: 'process', owner: 'accounts', permission: 'menu:finance', api: '/api/v1/workspace/payments'));
        $r->register(new ProductListCapability(), new CapabilityManifest(key: 'products', type: 'process', owner: 'warehouse', permission: 'menu:products', api: '/api/v1/workspace/products'));
        $r->register(new SupplierListCapability(), new CapabilityManifest(key: 'suppliers', type: 'process', owner: 'purchasing', permission: 'menu:purchases', api: '/api/v1/workspace/suppliers'));
        $r->register(new CategoryListCapability(), new CapabilityManifest(key: 'categories', type: 'process', owner: 'warehouse', permission: 'menu:products', api: '/api/v1/workspace/categories'));
        $r->register($purchase, new CapabilityManifest(key: 'purchase.process', type: 'process', owner: 'purchasing', permission: 'menu:purchases', api: '/api/v1/workspace/purchase.process'));
        $r->register(new DemandPlanListCapability(), new CapabilityManifest(key: 'demand.plans', type: 'process', owner: 'purchasing', permission: 'menu:purchases', commands: [['key' => 'create_plan', 'actor' => 'human']], api: '/api/v1/workspace/demand.plans'));
        $r->register(new ProcurementDashboardCapability(), new CapabilityManifest(key: 'procurement.hub', type: 'process', owner: 'purchasing', permission: 'menu:purchases', api: '/api/v1/workspace/procurement.hub'));
        $r->register(new JubaStockCapability(), new CapabilityManifest(key: 'juba.stock', type: 'process', owner: 'warehouse', permission: 'menu:products', api: '/api/v1/workspace/juba.stock'));
        $r->register(new FinanceCapability($money), new CapabilityManifest(key: 'finance', type: 'process', owner: 'accounts', permission: 'menu:finance', api: '/api/v1/workspace/finance'));
        $r->register(new CashbookCapability(), new CapabilityManifest(key: 'cashbook', type: 'process', owner: 'accounts', permission: 'menu:finance', api: '/api/v1/workspace/cashbook'));
        $r->register($ads, new CapabilityManifest(key: 'ads', type: 'process', owner: 'management', permission: '', api: '/api/v1/workspace/ads'));
        $r->register(new CampaignListCapability(), new CapabilityManifest(key: 'campaigns', type: 'process', owner: 'sales', permission: 'menu:customers', api: '/api/v1/workspace/campaigns'));
        $r->register($warehouse, new CapabilityManifest(key: 'warehouse', type: 'process', owner: 'warehouse', permission: 'menu:products', api: '/api/v1/workspace/warehouse'));
        $r->register($dispatch, new CapabilityManifest(key: 'dispatch', type: 'process', owner: 'warehouse', permission: 'menu:products', api: '/api/v1/workspace/dispatch'));

        $r->register(new SetupCapability(), new CapabilityManifest(key: 'setup', type: 'process', owner: 'management', permission: '', api: '/api/v1/workspace/setup'));
        $r->register(new SettingsCapability(), new CapabilityManifest(key: 'settings', type: 'process', owner: 'management', permission: '', api: '/api/v1/workspace/settings'));

        $r->register(new WorklistCapability($collections, $sales, $purchase, $warehouse, $dispatch), new CapabilityManifest(
            key: 'worklist', type: 'process', owner: 'management',
            dependsOn: ['collections', 'sales.process', 'purchase.process', 'warehouse', 'dispatch'],
            permission: '', api: '/api/v1/workspace/worklist',
        ));
        $r->register(new MorningBriefingCapability($collections, $sales, $purchase, $warehouse, $dispatch, $money, $ads), new CapabilityManifest(
            key: 'briefing', type: 'process', owner: 'management',
            dependsOn: ['collections', 'sales.process', 'purchase.process', 'warehouse', 'dispatch'],
            events: ['emits' => ['BriefingGenerated']],
            permission: '', api: '/api/v1/workspace/briefing',
        ));
    }
}
