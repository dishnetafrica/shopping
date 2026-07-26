<?php

namespace App\Capability\Registry;

use App\Capability\Object\CustomerCapability;
use App\Capability\Object\DemandPlanCapability;
use App\Capability\Process\AdsCapability;
use App\Capability\Process\DemandPlanListCapability;
use App\Capability\Process\JubaStockCapability;
use App\Capability\Process\StorefrontInsightsCapability;
use App\Capability\Process\TasksCapability;
use App\Capability\Process\ProcurementDashboardCapability;
use App\Capability\Object\InvoiceCapability;
use App\Capability\Object\PaymentCapability;
use App\Capability\Object\ProductCapability;
use App\Capability\Object\PurchaseOrderCapability;
use App\Capability\Object\QuotationCapability;
use App\Capability\Object\SalesOrderCapability;
use App\Capability\Object\SupplierCapability;
use App\Capability\Object\WorkOrderCapability;
use App\Capability\Process\WorkOrderListCapability;
use App\Capability\Object\MaintenancePlanCapability;
use App\Capability\Process\MaintenancePlanListCapability;
use App\Capability\Process\CampaignListCapability;
use App\Capability\Process\CashbookCapability;
use App\Capability\Process\CategoryListCapability;
use App\Capability\Process\CollectionsCapability;
use App\Capability\Process\CustomerListCapability;
use App\Capability\Process\DispatchCapability;
use App\Capability\Process\InvoiceListCapability;
use App\Capability\Object\ChatCapability;
use App\Capability\Object\ConsignmentCapability;
use App\Capability\Process\ChatListCapability;
use App\Capability\Process\CommunicationCapability;
use App\Capability\Process\ConsignmentListCapability;
use App\Capability\Process\FollowupsCapability;
use App\Capability\Process\BotDiagnosticsCapability;
use App\Capability\Process\GuideCapability;
use App\Capability\Process\ScheduledOrdersCapability;
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
        // Migration plan G1 — the WhatsApp inbox in v3 (list + single-conversation object).
        $r->register(new ChatListCapability(), new CapabilityManifest(key: 'chats', type: 'process', owner: 'sales', permission: 'menu:whatsapp', api: '/api/v1/workspace/chats'));
        $r->register(new ChatCapability(), new CapabilityManifest(key: 'chat', type: 'object', owner: 'sales', permission: 'menu:whatsapp', api: '/api/v1/workspace/chat/{id}'));
        // Migration plan G2 — the Email Center as a v3 workspace (UI re-home; engine untouched).
        // Guarded: only register where the Comms backend (M1-M4 zip) is actually deployed,
        // so the menu never offers a workspace that would 500 — incident 2026-07-18.
        // Retire-audit tail: consignments (Kampala→Juba batches) — list + object. Open
        // permission on purpose: delivery is the FIELD role's job; management actions are
        // payload-gated and the papi endpoints re-enforce priv() server-side.
        $r->register(new ConsignmentListCapability(), new CapabilityManifest(key: 'consignments', type: 'process', owner: 'logistics', permission: '', api: '/api/v1/workspace/consignments'));
            // Hardened (2026-07-18 incident): ANY failure registering this capability = one
            // warning + one missing menu item — NEVER a dead panel.
            try {
                $r->register(new FollowupsCapability(), new CapabilityManifest(key: 'followups', type: 'process', owner: 'sales', permission: '', api: '/api/v1/workspace/followups'));
                $r->register(new BotDiagnosticsCapability(), new CapabilityManifest(key: 'diagnostics', type: 'process', owner: 'messaging', permission: '', api: '/api/v1/workspace/diagnostics'));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('followups capability not registered: '.$e->getMessage());
            }
        $r->register(new ConsignmentCapability(), new CapabilityManifest(key: 'consignment', type: 'object', owner: 'logistics', permission: '', api: '/api/v1/workspace/consignment/{id}'));

        // Retire-audit tail: scheduled ORDERS (future deliveries; ProcessScheduled cron).
        $r->register(new ScheduledOrdersCapability(), new CapabilityManifest(key: 'scheduled', type: 'process', owner: 'sales', permission: 'menu:orders', api: '/api/v1/workspace/scheduled'));

        // Asset Lifecycle · Phase 2 · S5b — Jobs / Work Orders (V3-native field service; no legacy screen).
        // Open permission on purpose: the FIELD team works jobs. Every write action posts to
        // /papi/work-orders, which re-enforces owner/manager server-side.
        $r->register(new WorkOrderListCapability(), new CapabilityManifest(key: 'jobs', type: 'process', owner: 'operations', permission: '', api: '/api/v1/workspace/jobs'));
        $r->register(new WorkOrderCapability(), new CapabilityManifest(key: 'workOrder', type: 'object', owner: 'operations', permission: '', api: '/api/v1/workspace/workOrder/{id}'));
        // S6b — recurring maintenance / service plans (V3-native). Same open-permission + /papi-gated pattern.
        $r->register(new MaintenancePlanListCapability(), new CapabilityManifest(key: 'maintenance', type: 'process', owner: 'operations', permission: '', api: '/api/v1/workspace/maintenance'));
        $r->register(new MaintenancePlanCapability(), new CapabilityManifest(key: 'maintenancePlan', type: 'object', owner: 'operations', permission: '', api: '/api/v1/workspace/maintenancePlan/{id}'));

        // Help & Training — the UCRM-style built-in manual (open to every role).
        $r->register(new GuideCapability(), new CapabilityManifest(key: 'guide', type: 'process', owner: 'management', permission: '', api: '/api/v1/workspace/guide'));

        if (class_exists(\App\Models\CommMessage::class)) {
            $r->register(new CommunicationCapability(), new CapabilityManifest(key: 'communication', type: 'process', owner: 'management', permission: 'menu:settings', api: '/api/v1/workspace/communication'));
        }
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
        $r->register(new StorefrontInsightsCapability(), new CapabilityManifest(key: 'storefront.insights', type: 'process', owner: 'management', permission: 'menu:products', api: '/api/v1/workspace/storefront.insights'));
        $r->register(app(TasksCapability::class), new CapabilityManifest(key: 'tasks', type: 'process', owner: 'management', permission: 'menu:tasks', commands: [['key' => 'assign', 'actor' => 'human'], ['key' => 'assignable', 'actor' => 'human'], ['key' => 'complete', 'actor' => 'human'], ['key' => 'start', 'actor' => 'human'], ['key' => 'done', 'actor' => 'human'], ['key' => 'bulk-done', 'actor' => 'human']], api: '/api/v1/workspace/tasks'));
        $r->register(new FinanceCapability($money), new CapabilityManifest(key: 'finance', type: 'process', owner: 'accounts', permission: 'menu:finance', api: '/api/v1/workspace/finance'));
        $r->register(new CashbookCapability(), new CapabilityManifest(key: 'cashbook', type: 'process', owner: 'accounts', permission: 'menu:cash', api: '/api/v1/workspace/cashbook'));
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
