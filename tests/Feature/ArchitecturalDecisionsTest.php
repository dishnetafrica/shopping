<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * System-level architectural decisions as executable guards — the layer ABOVE
 * ArchitectureBoundariesTest (file structure): these encode the rules the
 * design is built on. Every baseline below was MEASURED on 2026-07-18; the
 * suite passes on that tree and fails the moment the design drifts.
 *
 *   D1  The kernel is framework-free outside its infra adapter.
 *   D2  No business module sends mail directly — email goes through the
 *       Communication module (3 pinned legacy call sites, shrink-only).
 *   D3  WhatsApp gateway implementations stay behind WhatsAppManager.
 *   D4  Marketplace and ERP are isolated in BOTH directions (zero baseline).
 *   D5  Module public APIs are closed-world: only the published surface may be
 *       referenced from outside a module. (This is also how "Operations
 *       coordinates but doesn't own" is enforced: Ops publishes exactly
 *       TaskEngine.)
 */
class ArchitecturalDecisionsTest extends TestCase
{
    private function base(): string
    {
        return base_path();
    }

    private function phpFiles(string $dir): array
    {
        $abs = $this->base().'/'.$dir;
        if (! is_dir($abs)) return [];
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = substr($f->getPathname(), strlen($this->base()) + 1);
            }
        }
        sort($out);
        return $out;
    }

    // ---------------------------------------------------- D1: hexagonal kernel

    public function test_kernel_is_framework_free_outside_the_infra_adapter(): void
    {
        $bad = [];
        foreach ($this->phpFiles('cloudbss-kernel') as $rel) {
            if (str_starts_with($rel, 'cloudbss-kernel/infra/')) continue;   // the ONE adapter layer
            if (str_starts_with($rel, 'cloudbss-kernel/tests/')) continue;   // harness, not domain code
            if (preg_match('/\bIlluminate\\\\/', file_get_contents($this->base().'/'.$rel))) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad,
            "The domain kernel must not know Laravel exists — framework code belongs only in "
            ."cloudbss-kernel/infra/ (measured clean 2026-07-18):\n".implode("\n", $bad));
    }

    // ------------------------------------------- D2: one front door for email

    public function test_no_direct_mail_outside_the_communication_module(): void
    {
        // Pinned legacy call sites (each keeps a flag-off legacy branch or predates
        // the Email Service). SHRINK-ONLY: retire a line when the site is migrated.
        $legacy = [
            'app/Console/Commands/BotReplayCommand.php',
            'app/Console/Commands/CashbookDigestCommand.php',
            'app/Http/Controllers/Storefront/QuotationPortalController.php',
        ];

        $bad = [];
        foreach ($this->phpFiles('app') as $rel) {
            if (str_starts_with($rel, 'app/Services/Comms/')) continue;     // the module IS the transport
            if (in_array($rel, $legacy, true)) continue;
            if (preg_match('/\bMail::/', file_get_contents($this->base().'/'.$rel))) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad,
            "Direct Mail:: usage outside the Communication module — new email goes through "
            ."App\\Support\\Comms / EmailService (D2):\n".implode("\n", $bad));
    }

    // ------------------------------- D3: gateways stay behind WhatsAppManager

    public function test_whatsapp_gateway_implementations_stay_behind_the_manager(): void
    {
        // Pinned legacy sites touching gateway classes directly (admin-plane usage).
        $legacy = [
            'app/Console/Commands/WaWatchdogCommand.php',
            'app/Http/Controllers/Panel/PanelApiController.php',
        ];

        $pattern = '/new (EvolutionGateway|CloudApiGateway)|Services\\\\WhatsApp\\\\(EvolutionGateway|CloudApiGateway|EvolutionAdmin)/';
        $bad = [];
        foreach ($this->phpFiles('app') as $rel) {
            if (str_starts_with($rel, 'app/Services/WhatsApp/')) continue;
            if (in_array($rel, $legacy, true)) continue;
            if (preg_match($pattern, file_get_contents($this->base().'/'.$rel))) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad,
            "Gateway implementations referenced directly — use WhatsAppManager::forTenant() / "
            ."the WhatsAppGateway contract (D3):\n".implode("\n", $bad));
    }

    // ------------------------------------------ D4: Marketplace ⟂ ERP, both ways

    public function test_marketplace_and_erp_are_isolated(): void
    {
        $erp = ['Sales', 'Invoicing', 'Ledger', 'Finance', 'Inventory', 'Purchasing', 'Procurement', 'Billing'];

        $bad = [];
        foreach ($this->phpFiles('app/Services/Marketplace') as $rel) {
            $code = file_get_contents($this->base().'/'.$rel);
            foreach ($erp as $d) {
                if (preg_match('/App\\\\Services\\\\'.$d.'\\\\/', $code)) $bad[] = "$rel → {$d}";
            }
        }
        foreach ($erp as $d) {
            foreach ($this->phpFiles('app/Services/'.$d) as $rel) {
                if (preg_match('/App\\\\Services\\\\Marketplace\\\\/', file_get_contents($this->base().'/'.$rel))) {
                    $bad[] = "$rel → Marketplace";
                }
            }
        }
        $this->assertSame([], $bad,
            "Marketplace and ERP must stay isolated (bridge belongs in a Settlement seam, "
            ."not direct imports — measured ZERO edges 2026-07-18):\n".implode("\n", $bad));
    }

    // ----------------------------------- D5: closed-world module public APIs

    public function test_module_public_apis_are_closed_world(): void
    {
        // Published surface per module = the first namespace segment after the module,
        // MEASURED from actual external references on 2026-07-18. Closed-world:
        // referencing anything else from outside the module fails. Publishing a new
        // class = one line here AND in MODULE-MAP.md. Shrink over time.
        $published = [
            'Marketplace' => ['ProviderRegistry', 'MarketplaceSearch', 'Providers', 'Dto', 'Support'],
            'Ops'         => ['TaskEngine'],   // Operations coordinates; it does not own business data
            'Delivery'    => ['DeliveryService', 'RiderAssigner', 'ZoneResolver', 'DeliveryStatus'],
            'Logistics'   => ['ShipmentService', 'LastMileBridge', 'ShipmentStateMachine', 'BoxCustodyService'],
            'Ledger'      => ['StatementMessenger', 'OrderLedgerService'],
            'Sales'       => ['QuotationWriteService', 'QuotationConvertService', 'QuotationIntelligence', 'QuotationReviseService'],
            'Inventory'   => ['StockLedger', 'InventoryState', 'OrderStockIssuer', 'StockBackfill'],
            'Bot'         => [   // wide surface, frozen as-is; every retirement is progress
                'LocationDictionary', 'ProductAlias', 'ShoppingParser', 'AiBrain', 'Pricing',
                'QuotationService', 'LeadScorer', 'BotBrain', 'BotNlu', 'Validation', 'Company',
                'Discovery', 'Readiness', 'Opportunity', 'PriceListService', 'Search',
                'CatalogueMatcher', 'LeadDedupe', 'LeadImport', 'OrderCalculator', 'Offers',
                'ThaliMenu', 'IntentRouter', 'LeadService', 'MarketingBrain', 'VisionProductFinder',
                'VoiceTranscriber', 'Merchant', 'BusinessOpportunityEngine', 'ComboAnalytics',
                'ComboEngine', 'ProductImageResponder',
                'MissedReply',   // 2026-11-17: missed-reply recovery rules, used by bot:recover-missed (MODULE-MAP)
            ],
        ];

        $bad = [];
        $appFiles = $this->phpFiles('app');
        foreach ($published as $mod => $surface) {
            foreach ($appFiles as $rel) {
                if (str_starts_with($rel, "app/Services/{$mod}/")) continue;
                $code = file_get_contents($this->base().'/'.$rel);
                if (preg_match_all('/App\\\\Services\\\\'.$mod.'\\\\([A-Za-z]+)/', $code, $m)) {
                    foreach (array_unique($m[1]) as $token) {
                        if (! in_array($token, $surface, true)) {
                            $bad[] = "$rel → {$mod}\\{$token}";
                        }
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($bad)),
            "Unpublished module internals referenced from outside — go through the module's "
            ."published API, or publish deliberately in this test + MODULE-MAP.md (D5):\n"
            .implode("\n", array_unique($bad)));
    }

    // -------------------------------- D6: V3 is the default for new development

    /**
     * Directive 2026-07-18 (V3-DEFAULT-MIGRATION-PLAN.md): every NEW screen is a
     * Capability under app/Capability rendered by the /v3 shell. The classic panel
     * (60 pinned pages) is frozen — bug fixes only. A new .html here fails.
     */
    public function test_no_new_classic_panel_screens(): void
    {
        $pinned = [
            'ads.html',
            'app_home.html',
            'audit.html',
            'billing.html',
            'book_cost.html',
            'books.html',
            'buy_list.html',
            'cash_position.html',
            'cashbook.html',
            'chats.html',
            'consignment.html',
            'cost_approvals.html',
            'credit-notes.html',
            'customers360.html',
            'dashboard.html',
            'diagnostics.html',
            'email-center.html',
            'exceptions.html',
            'expenses.html',
            'field-task.html',
            'guide.html',
            'indent.html',
            'inventory-count.html',
            'invoiced.html',
            'invoices.html',
            'leads.html',
            'ledger.html',
            'maintenance.html',
            'marketing.html',
            'mobile.html',
            'my_cash.html',
            'oif.html',
            'onboard.html',
            'orders_costs.html',
            'partners.html',
            'payments-manager.html',
            'payments.html',
            'planning.html',
            'pnl-report.html',
            'pnl.html',
            'production.html',
            'purchase.html',
            'sales.html',
            'scheduled.html',
            'scoreboard.html',
            'seller.html',
            'settings-manager.html',
            'setup.html',
            'sourcing.html',
            'staff.html',
            'staff_cash.html',
            'start.html',
            'supplier_debt.html',
            'suppliers.html',
            'system.html',
            'tasks.html',
            'today.html',
            'trade_cashbook.html',
            'training.html',
            'winworld-hub.html',
        ];
        $found = [];
        foreach (scandir($this->base().'/resources/panel') as $f) {
            if (str_ends_with($f, '.html')) $found[] = $f;
        }
        $new = array_values(array_diff($found, $pinned));
        $this->assertSame([], $new,
            "New classic-panel screen detected — V3 is the default: build it as a Capability "
            ."under app/Capability (see V3-DEFAULT-MIGRATION-PLAN.md rule 1):\n".implode("\n", $new));
    }
}
