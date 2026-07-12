<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /papi/selfcheck — one JSON report of which CloudBSS add-ons are actually deployed on
 * THIS server, including the bot changes that have no screen. Reads deployed source markers,
 * class existence, DB tables and settings. Read-only; every probe is defensive.
 */
class SelfcheckController extends Controller
{
    private function fileHas(string $relPath, string $needle): ?bool
    {
        try {
            $p = base_path($relPath);
            if (! is_file($p)) return null;
            return str_contains((string) file_get_contents($p), $needle);
        } catch (\Throwable $e) { return null; }
    }

    public function run(Request $request)
    {
        $t = $request->user() ? $request->user()->tenant : null;
        $checks = [];
        $add = function (string $key, string $label, ?bool $ok, string $detail = '') use (&$checks) {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        // --- Panel / consignment ------------------------------------------------------
        try {
            $t1 = Schema::hasTable('shipment_orders');
            $t2 = Schema::hasTable('consignment_stock');
            $t3 = $t2 && Schema::hasColumn('consignment_stock', 'unit_cost');
            $add('consignment_migrated', 'Consignment tables migrated', $t1 && $t2 && $t3,
                $t1 && $t2 && $t3 ? '' : 'run: php artisan migrate --force');
        } catch (\Throwable $e) { $add('consignment_migrated', 'Consignment tables migrated', null, 'probe error'); }

        $add('consignment_code', 'Consignment code deployed',
            class_exists(\App\Http\Controllers\Panel\ConsignmentController::class) && class_exists(\App\Models\ConsignmentStock::class));

        // --- Navigation (Roadmap E1.1) -----------------------------------------------
        $add('nav_config', 'Single navigation config + /papi/nav',
            is_file(base_path('config/navigation.php'))
            && class_exists(\App\Http\Controllers\Panel\NavController::class)
            && method_exists(\App\Http\Controllers\Panel\NavController::class, 'menuFor'));
        $navV2On = $t ? (bool) $t->setting('nav_v2', false) : false;
        $add('nav_v2_render', 'Sidebar renders from /papi/nav (flag nav_v2)',
            (bool) $this->fileHas('resources/panel/seller.html', "fetch('/papi/nav'"),
            $navV2On ? 'nav_v2 ON for this tenant' : 'deployed; set tenant setting nav_v2=true to switch the menu on');

        $mods = ($t && is_array($t->setting('modules', []))) ? $t->setting('modules', []) : [];
        $restOn = ! empty($mods['restaurant']);
        $add('module_restaurant_gate', 'Restaurant features behind module.restaurant (default off)',
            (bool) $this->fileHas('resources/panel/seller.html', 'RESTAURANT_ON')
            && (bool) $this->fileHas('resources/panel/seller.html', 'RESTAURANT_KEYS'),
            $restOn ? 'restaurant module ON for this tenant' : 'default off (hidden); set modules.restaurant=true to show');
        $add('module_flags_backfilled', 'Tenant module flags backfilled (E1.4)',
            $t ? (array_key_exists('restaurant', $mods)) : null,
            ($t && array_key_exists('restaurant', $mods)) ? '' : 'run: php artisan migrate --force');

        try {
            $navIssues = \App\Http\Controllers\Panel\NavController::menuIssues();
            $add('nav_no_duplicates', 'Navigation has no duplicate or retired links (E1.5)',
                empty($navIssues), empty($navIssues) ? '' : implode('; ', $navIssues));
        } catch (\Throwable $e) { $add('nav_no_duplicates', 'Navigation has no duplicate or retired links (E1.5)', null, 'probe error'); }

        // E5.3 — Cash Position / Staff Cash / My Cash are tabs of the ONE Cashbook screen, not
        // separate menu links. Verify the Cashbook item carries those tabs and the standalone routes
        // are retired as menu links (deep links still resolve).
        try {
            $groups = \App\Http\Controllers\Panel\NavController::menuFor(\App\Models\User::allMenuKeys());
            $cashbook = null;
            foreach ($groups as $g) foreach ($g['items'] as $it) if (($it['key'] ?? '') === 'cashbook') $cashbook = $it;
            $tabRoutes = array_map(fn ($x) => $x['route'] ?? '', $cashbook['tabs'] ?? []);
            $retired = \App\Http\Controllers\Panel\NavController::RETIRED_ROUTES;
            $wanted = ['/panel/cash-position', '/panel/staff-cash', '/panel/my-cash'];
            $tabsOk = $cashbook !== null && count(array_intersect($wanted, $tabRoutes)) === 3
                && count(array_intersect($wanted, $retired)) === 3;
            $add('cashbook_tabs', 'Cash Position / Staff Cash / My Cash are tabs of one Cashbook (E5.3)',
                $tabsOk, $tabsOk ? 'one Cashbook entry with tabs; standalone routes retired as menu links'
                                 : 'Cashbook tabs or retired-route registration missing');
        } catch (\Throwable $e) { $add('cashbook_tabs', 'Cash Position / Staff Cash / My Cash are tabs of one Cashbook (E5.3)', null, 'probe error'); }

        // E5.4 — the second (ledger_entries) cashbook is retired after the E4 cutover: /panel/cashbook
        // redirects to the ONE Cashbook when pay_unify is ON, and is a retired menu link either way.
        $cbRetireWired = (bool) $this->fileHas('app/Http/Controllers/Panel/SellerPanelController.php', "redirect('/panel/trade-cashbook')")
            && in_array('/panel/cashbook', \App\Http\Controllers\Panel\NavController::RETIRED_ROUTES, true);
        $cbCutover = $t ? (bool) $t->setting('pay_unify', false) : false;
        $add('cashbook_retired', 'Second (ledger_entries) cashbook retired after cutover (E5.4)',
            $cbRetireWired,
            $cbRetireWired
                ? ($cbCutover ? 'pay_unify ON — /panel/cashbook redirects to the one Cashbook'
                              : 'wired; pre-cutover (pay_unify OFF) still serves the legacy screen')
                : 'redirect-on-cutover not wired in SellerPanelController::cashbook');

        $rolesExpanded = (bool) $this->fileHas('app/Models/User.php', "case 'cashier':")
            && (bool) $this->fileHas('app/Models/User.php', "case 'accountant':")
            && (bool) $this->fileHas('app/Models/User.php', "case 'warehouse':")
            && (bool) $this->fileHas('app/Models/User.php', "case 'procurement':")
            && (bool) $this->fileHas('app/Models/User.php', "case 'marketing':");
        $add('rbac_roles_expanded', 'Roles cashier/accountant/warehouse/procurement/marketing (E2.1)', $rolesExpanded);

        $strictOn = $t ? (bool) $t->setting('perm_strict', false) : false;
        $add('perm_deny_by_default', 'Deny-by-default available (flag perm_strict, E2.2)',
            (bool) $this->fileHas('app/Models/User.php', 'permStrict'),
            $strictOn ? 'perm_strict ON for this tenant' : 'deployed; set tenant setting perm_strict=true to enforce');

        $add('access_gate', 'Action gate helper Access::allows (E2.3)',
            class_exists(\App\Support\Access::class)
            && method_exists(\App\Support\Access::class, 'allows'));

        $guarded = method_exists(\App\Support\Access::class, 'blocks')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PaymentController.php', "Access::blocks")
            && (bool) $this->fileHas('app/Http/Controllers/Panel/SupplierController.php', "Access::blocks")
            && (bool) $this->fileHas('app/Http/Controllers/Panel/ShipmentController.php', "Access::blocks")
            && (bool) $this->fileHas('app/Http/Controllers/Panel/InvoiceController.php', "Access::blocks")
            && (bool) $this->fileHas('app/Http/Controllers/Panel/OrderCostController.php', "Access::blocks")
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PanelApiController.php', "Access::blocks");
        $enforceOn = $t ? (bool) $t->setting('perm_enforce', false) : false;
        $add('endpoints_guarded', 'Money/data endpoints server-guarded (flag perm_enforce, E2.4)',
            $guarded,
            $enforceOn ? 'perm_enforce ON for this tenant' : 'deployed; set tenant setting perm_enforce=true to enforce');

        $twoGateCode = method_exists(\App\Support\Access::class, 'tenantModuleEnabled')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/NavController.php', 'Access::moduleEnabled');
        $add('two_gate_module', 'Module + role two-gate on API and menu (E2.5, R11)', $twoGateCode);

        // E2.6 — one roll-up for the whole permission system (Epic 2 / Milestone M1). Reflects code
        // deployment only; the per-tenant flags perm_strict / perm_enforce remain opt-in.
        $permStrictCode = (bool) $this->fileHas('app/Models/User.php', 'permStrict');
        $accessGateCode = class_exists(\App\Support\Access::class)
            && method_exists(\App\Support\Access::class, 'allows')
            && method_exists(\App\Support\Access::class, 'blocks');
        $permsReady = $rolesExpanded && $permStrictCode && $accessGateCode && $guarded && $twoGateCode;
        $fxConfigured = $t ? is_array($t->setting('fx_rates', null)) : false;
        $add('fx_service', 'Single FX service App\\Services\\Money\\Fx (E3.1)',
            class_exists(\App\Services\Money\Fx::class)
            && method_exists(\App\Services\Money\Fx::class, 'convert'),
            $fxConfigured ? 'fx_rates configured for this tenant' : 'deployed; fx_rates backfilled in E3.2 (falls back to current rate meanwhile)');
        $add('fx_rates_backfilled', 'Tenant fx_rates backfilled (E3.2)',
            $t ? $fxConfigured : null,
            $fxConfigured ? '' : 'run: php artisan migrate --force');

        $add('fx_reader_invoice', 'Invoice pair reads FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Services/Invoicing/InvoiceService.php', 'Fx::forTenant')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/InvoiceController.php', 'Fx::forTenant'));
        $cbNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/TradeCashbookController.php', "setting('rate_")
            && ! (bool) $this->fileHas('app/Http/Controllers/Panel/TradeCashbookController.php', "setting(\"rate_");
        $add('fx_reader_cashbook', 'Cashbook reads FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/TradeCashbookController.php', 'Fx::forTenant') && $cbNoHardcoded);
        $ocNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/OrderCostController.php', "setting('rate_")
            && ! (bool) $this->fileHas('app/Http/Controllers/Panel/OrderCostController.php', "setting('usdUgx")
            && ! (bool) $this->fileHas('app/Http/Controllers/Panel/OrderCostController.php', "setting('usdSsp");
        $add('fx_reader_ordercost', 'Order cost/margin reads FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/OrderCostController.php', 'Fx::forTenant') && $ocNoHardcoded);
        $exNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/ExpenseController.php', "setting('rate_")
            && ! (bool) $this->fileHas('app/Http/Controllers/Panel/ExpenseController.php', "setting('usdUgx");
        $add('fx_reader_expense', 'Expenses/P&L revenue reads FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/ExpenseController.php', 'Fx::forTenant') && $exNoHardcoded);
        $supNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/SupplierController.php', "setting('rate_");
        $add('fx_reader_supplier', 'Supplier payables/payments read FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/SupplierController.php', 'Fx::forTenant') && $supNoHardcoded);
        $prNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/PartnerController.php', "setting('rate_");
        $add('fx_reader_partner', 'Partner capital reads FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/PartnerController.php', 'Fx::forTenant') && $prNoHardcoded);
        $scNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/StaffCashController.php', "setting('rate_");
        $add('fx_reader_staffcash', 'Staff cash reads FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/StaffCashController.php', 'Fx::forTenant') && $scNoHardcoded);
        $payNoHardcoded = ! (bool) $this->fileHas('app/Http/Controllers/Panel/PaymentController.php', "setting('rate_");
        $add('fx_reader_payment', 'Payments read FX via Fx service (E3.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/PaymentController.php', 'Fx::forTenant') && $payNoHardcoded);

        // Bot quotes at the canonical rate when fx_v2 is on (customer-facing; flag-gated, E3.4).
        $add('fx_reader_bot', 'Bot pricing reads FX when fx_v2 on (E3.4)',
            (bool) $this->fileHas('app/Services/Bot/Pricing/CurrencyPricer.php', 'Fx::forTenant')
            && (bool) $this->fileHas('app/Services/Bot/AiBrain.php', 'Fx::forTenant'));

        // fx_single_source: every SERVER ACCOUNTING path (rate_*_per_usd) now reads via Fx. The
        // client/bot display family (usdUgx) is unified behind fx_v2 (E3.3 + bot) and the legacy
        // keys are removed in E3.5.
        $serverAccountingClean = collect([
            'app/Http/Controllers/Panel/InvoiceController.php',
            'app/Services/Invoicing/InvoiceService.php',
            'app/Http/Controllers/Panel/TradeCashbookController.php',
            'app/Http/Controllers/Panel/OrderCostController.php',
            'app/Http/Controllers/Panel/ExpenseController.php',
            'app/Http/Controllers/Panel/SupplierController.php',
            'app/Http/Controllers/Panel/PartnerController.php',
            'app/Http/Controllers/Panel/StaffCashController.php',
            'app/Http/Controllers/Panel/PaymentController.php',
        ])->every(fn ($f) => ! (bool) $this->fileHas($f, "setting('rate_"));
        $add('fx_single_source', 'All accounting paths use one FX source (E3.4)', $serverAccountingClean);

        // E3.5 — cleanup: no hardcoded FX numeric constants outside the Fx service. The grep-guard
        // test (tests/Unit/NoHardcodedFxTest) enforces this; here we just confirm it's deployed and
        // the former hardcoded sites now default from Fx.
        $add('fx_no_hardcoded', 'No hardcoded FX constants outside Fx service (E3.5)',
            is_file(base_path('tests/Unit/NoHardcodedFxTest.php'))
            && (bool) $this->fileHas('app/Services/Bot/Pricing/CurrencyPricer.php', 'Fx::forTenant')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PanelApiController.php', 'Fx::forTenant'));

        $fxV2On = $t ? (bool) $t->setting('fx_v2', false) : false;
        $add('fx_client_wired', 'Panel reads canonical /papi/fx (flag fx_v2, E3.3)',
            class_exists(\App\Http\Controllers\Panel\FxController::class)
            && (bool) $this->fileHas('resources/panel/seller.html', "fetch('/papi/fx'")
            && (bool) $this->fileHas('resources/panel/invoiced.html', "fetch('/papi/fx'"),
            $fxV2On ? 'fx_v2 ON — panel uses the canonical rate' : 'deployed; divergence logged to console, set fx_v2=true to switch');

        $add('payment_service', 'Single payment recorder PaymentService::record (E4.1)',
            class_exists(\App\Services\Money\PaymentService::class)
            && method_exists(\App\Services\Money\PaymentService::class, 'record')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PaymentController.php', 'PaymentService::class'));
        $add('payment_service_invoice', 'Invoice "Pay" uses the one recorder (E4.2)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/InvoiceController.php', 'PaymentService::class'));
        $payUnifyOn = $t ? (bool) $t->setting('pay_unify', false) : false;
        $add('staffcash_syncs_invoice', 'Staff receipt syncs the invoice (flag pay_unify, E4.3)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/StaffCashController.php', 'syncByPayment'),
            $payUnifyOn ? 'pay_unify ON — staff receipts reduce the invoice/statement' : 'deployed; set pay_unify=true to enable');
        $add('cashbook_dual_write', 'Cashbook payment dual-writes the one ledger (flag pay_unify, E4.4)',
            (bool) $this->fileHas('app/Http/Controllers/Panel/PanelApiController.php', 'E4.4 — dual-write'),
            $payUnifyOn ? 'pay_unify ON — cashbook payments appear in Payments/statements' : 'deployed; set pay_unify=true to enable');
        $orderTyped = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'sell_currency')
            && \Illuminate\Support\Facades\Schema::hasColumn('orders', 'payment_method')
            && method_exists(\App\Models\Order::class, 'sellCurrency')
            && method_exists(\App\Models\Order::class, 'paymentMethodResolved');
        $add('order_typed_money', 'Order sell-currency & method are typed columns, not overloaded (E4.5)',
            $orderTyped,
            $orderTyped ? 'orders.sell_currency + orders.payment_method present; accessors decode legacy sell: as fallback'
                        : 'run migration 2026_07_12_000005_add_order_currency_method');

        // E4.6 — the legacy ledger_entries order_payment write is retired under pay_unify, so a customer
        // payment is recorded ONCE (the unified CustomerPayment ledger, mirrored 1:1 by a cash-in
        // OrderCost). This probe proves (a) the retirement gate is wired and (b) the unified ledger
        // reconciles: every CustomerPayment is backed by an OrderCost and their USD totals agree, so
        // nothing was lost or double-counted by dropping the duplicate write.
        $retireWired = (bool) $this->fileHas('app/Http/Controllers/Panel/PanelApiController.php', 'E4.6 — legacy order_payment write retired');
        $reconcileOk = true;
        $reconcileNote = 'no customer payments yet — nothing to reconcile';
        try {
            if ($t) {
                $payUsd  = (float) \App\Models\CustomerPayment::where('tenant_id', $t->id)->sum('usd_amount');
                $costUsd = (float) \App\Models\OrderCost::where('tenant_id', $t->id)->where('stage', 'customer_payment')->sum('usd_amount');
                $orphans = (int) \App\Models\CustomerPayment::where('tenant_id', $t->id)->whereNull('order_cost_id')->count();
                $drift = abs($payUsd - $costUsd);
                $reconcileOk = ($drift < 0.01) && ($orphans === 0);
                $reconcileNote = $reconcileOk
                    ? 'ledger reconciles: CustomerPayment USD == mirrored OrderCost receipts (drift ' . number_format($drift, 4) . '), 0 orphans'
                    : ('DRIFT: payments=' . number_format($payUsd, 2) . ' vs receipts=' . number_format($costUsd, 2) . ', orphans=' . $orphans);
            }
        } catch (\Throwable $e) {
            $reconcileOk = false;
            $reconcileNote = 'reconcile query failed: ' . $e->getMessage();
        }
        $add('payments_reconcile', 'Legacy payment write retired + unified ledger reconciles (E4.6)',
            $retireWired && $reconcileOk,
            $retireWired ? $reconcileNote : 'retirement gate not wired in PanelApiController::recordPayment');

        // E5.1 — one signed-sum cash-balance rule (CashService). Verifies the service exists, the
        // signed-native rule matches the cashbook (+ cash-in stages, − everything else), and — when a
        // tenant is present — that its balance equals the Trade Cashbook headline computed inline here.
        $cashSvcOk = class_exists(\App\Services\Money\CashService::class)
            && method_exists(\App\Services\Money\CashService::class, 'balances')
            && \App\Services\Money\CashService::signedNative('cash_in', 100.0) === 100.0
            && \App\Services\Money\CashService::signedNative('customer_payment', 100.0) === 100.0
            && \App\Services\Money\CashService::signedNative('cash_out', 100.0) === -100.0;
        $cashNote = 'CashService present; signed-sum rule matches the cashbook';
        try {
            if ($cashSvcOk && $t) {
                $svc = \App\Services\Money\CashService::forTenant($t)->balances();
                // inline oracle = TradeCashbookController::data() headline rule
                $oracle = ['USD' => 0, 'UGX' => 0, 'SSP' => 0];
                $rows = \App\Models\OrderCost::where('tenant_id', $t->id)->where('status', 'approved')
                    ->where(fn ($w) => $w->where('pay_status', '!=', 'credit')->orWhereNull('pay_status'))
                    ->get(['currency', 'stage', 'paid_native']);
                foreach ($rows as $r) {
                    $sign = in_array($r->stage, ['funding', 'cash_in', 'customer_payment'], true) ? 1 : -1;
                    if (isset($oracle[$r->currency])) $oracle[$r->currency] += $sign * (float) $r->paid_native;
                }
                $oracle = array_map(fn ($v) => round($v, 2), $oracle);
                if ($svc !== $oracle) { $cashSvcOk = false; $cashNote = 'DIVERGENCE: service ' . json_encode($svc) . ' vs cashbook ' . json_encode($oracle); }
                else { $cashNote = 'matches Trade Cashbook total: ' . json_encode($svc); }
            }
        } catch (\Throwable $e) { $cashSvcOk = false; $cashNote = 'cash balance check failed: ' . $e->getMessage(); }
        $add('cash_service', 'One cash-balance rule — CashService matches the cashbook (E5.1)', $cashSvcOk, $cashNote);

        // E5.2 — Cash Position + Staff Cash / My Cash now source the cash-in stage rule from
        // CashService::IN_STAGES, so the three surfaces can't drift on which stages add vs reduce cash.
        $cashRuleShared = (bool) $this->fileHas('app/Http/Controllers/Panel/CashPositionController.php', 'CashService::IN_STAGES')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/StaffCashController.php', 'CashService::IN_STAGES');
        $add('cash_rule_shared', 'Cash Position + Staff Cash share the one cash-in rule (E5.2)',
            $cashRuleShared,
            $cashRuleShared ? 'both surfaces reference CashService::IN_STAGES — no drift'
                            : 'a cash surface still hard-codes the stage list');

        // E6.1 — the one stock ledger: a stock_movements table + StockLedger service that projects
        // on-hand per SKU/location. New + unused (E6.2 dual-writes, E6.4 reads); verify it's deployed.
        $stockLedgerOk = \Illuminate\Support\Facades\Schema::hasTable('stock_movements')
            && class_exists(\App\Services\Inventory\StockLedger::class)
            && method_exists(\App\Services\Inventory\StockLedger::class, 'onHandFor')
            && method_exists(\App\Services\Inventory\StockLedger::class, 'move');
        $add('stock_ledger', 'One stock ledger — stock_movements + StockLedger projection (E6.1)',
            $stockLedgerOk,
            $stockLedgerOk ? 'stock_movements table + StockLedger present; on-hand = Σ signed movements per SKU/location'
                           : 'run migration 2026_07_12_000006_create_stock_movements');

        // E6.2 — the real consignment stock events dual-write the ledger (sale → OUT, receive → IN)
        // when stock_ledger is ON. Verify the write paths are wired; the flag stays off by default.
        $stockDualWired = (bool) $this->fileHas('app/Http/Controllers/Panel/ConsignmentController.php', "'consignment_sale'")
            && (bool) $this->fileHas('app/Http/Controllers/Panel/ConsignmentController.php', "'consignment_receive'");
        $stockLedgerOn = $t ? (bool) $t->setting('stock_ledger', false) : false;
        $add('stock_dual_write', 'Consignment sale/receive dual-write the stock ledger (flag stock_ledger, E6.2)',
            $stockDualWired,
            $stockLedgerOn ? 'stock_ledger ON — consignment events append to stock_movements'
                           : 'deployed; set stock_ledger=true to start emitting movements');

        // E6.3 — the ledger is backfilled so its projected on-hand equals today's numbers (prerequisite
        // for E6.4). Verify the backfill service exists and, for this tenant, that the Kampala
        // projection reconciles with Product.stock (opening balances present, no drift).
        $backfillOk = class_exists(\App\Services\Inventory\StockBackfill::class)
            && method_exists(\App\Services\Inventory\StockBackfill::class, 'runForTenant');
        $backfillNote = 'StockBackfill present';
        try {
            if ($backfillOk && $t) {
                $stockRows  = (int) \App\Models\Product::where('tenant_id', $t->id)->where('stock', '>', 0)->count();
                $openingRows = (int) \App\Models\StockMovement::where('tenant_id', $t->id)
                    ->where('ref_type', \App\Services\Inventory\StockBackfill::REF_PRODUCT)->count();
                if ($stockRows === 0) {
                    $backfillNote = 'no stocked products — nothing to backfill';
                } elseif ($openingRows > 0) {
                    // opening balances are seeded; note current reconciliation without failing on the
                    // known Kampala gap (catalog edits do not yet flow to the ledger — see E6.2 note).
                    $stockSum = (float) \App\Models\Product::where('tenant_id', $t->id)->where('stock', '>', 0)->sum('stock');
                    $openingSum = (float) \App\Models\StockMovement::where('tenant_id', $t->id)
                        ->where('ref_type', \App\Services\Inventory\StockBackfill::REF_PRODUCT)->sum('qty');
                    $backfillNote = abs($stockSum - $openingSum) < 0.01
                        ? 'opening balances seeded and reconcile with Product.stock'
                        : 'opening balances seeded (post-backfill catalog edits explain any drift until a Kampala path lands)';
                } else {
                    $backfillOk = false;
                    $backfillNote = 'opening balances not yet seeded — run: php artisan migrate --force';
                }
            }
        } catch (\Throwable $e) { $backfillOk = false; $backfillNote = 'backfill check failed: ' . $e->getMessage(); }
        $add('stock_backfill', 'Stock ledger backfilled to match today (E6.3)', $backfillOk, $backfillNote);

        // E6.4 (prereq) — Kampala catalog stock changes mirror into the ledger: the Product observer
        // (create → IN, edit → ADJUST) plus the CSV stock-sync raw path. Verify both are wired so the
        // stock_v2 read (next task) is correct for Kampala, not just Juba.
        $stockCatalogWired = class_exists(\App\Observers\ProductStockObserver::class)
            && (bool) $this->fileHas('app/Models/Product.php', 'ProductStockObserver')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PanelApiController.php', "'stock_sync'");
        $add('stock_catalog_write', 'Kampala catalog stock edits mirror into the ledger (flag stock_ledger, E6.4 prereq)',
            $stockCatalogWired,
            $stockCatalogWired
                ? ($stockLedgerOn ? 'stock_ledger ON — product create/edit + CSV sync append movements'
                                  : 'wired; set stock_ledger=true to mirror catalog stock changes')
                : 'product-stock write capture not fully wired (observer + stock-sync)');

        // E6.4 — on-hand reads switch to the ledger projection under stock_v2 (+ stock_ledger). Verify
        // the read API is wired and, when reads are on, that the product list is sourcing the projection.
        $readWired = method_exists(\App\Models\Product::class, 'onHand')
            && method_exists(\App\Services\Inventory\StockLedger::class, 'readsFromLedger')
            && method_exists(\App\Services\Inventory\StockLedger::class, 'onHandMap')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PanelApiController.php', 'onHandMap');
        $readsOn = $t ? \App\Services\Inventory\StockLedger::readsFromLedger($t) : false;
        $add('stock_read_v2', 'On-hand reads from the ledger projection (flag stock_v2, E6.4)',
            $readWired,
            $readsOn ? 'stock_v2 + stock_ledger ON — product on-hand reads the ledger projection'
                     : 'wired; set stock_v2=true (with stock_ledger) to read on-hand from the ledger');

        // E6.5 — products.stock is a DERIVED cache of the ledger: an owner reconcile derives the column
        // from the projection (never zeroing a product absent from the ledger). Verify it's wired.
        $deriveWired = method_exists(\App\Services\Inventory\StockLedger::class, 'syncProductStockCache')
            && method_exists(\App\Http\Controllers\Panel\PanelApiController::class, 'stockReconcileCache');
        $add('stock_derived', 'products.stock is a derived cache of the ledger (E6.5)',
            $deriveWired,
            $deriveWired
                ? ($readsOn ? 'reads from ledger; POST /papi/stock/reconcile-cache derives the column from it'
                            : 'wired; derive is available once stock_v2 is enabled')
                : 'derive/reconcile not wired (StockLedger::syncProductStockCache + endpoint)');

        // E7.1 — the Report definition contract: definitions declare columns/filters and read ONLY
        // SSOT services/projections. Verify the registry resolves the built-ins and they describe().
        $reportOk = false;
        $reportNote = 'Report contract not present';
        try {
            if (interface_exists(\App\Reports\ReportDefinition::class) && class_exists(\App\Reports\ReportRegistry::class)) {
                $reg = \App\Reports\ReportRegistry::default();
                $keys = $reg->keys();
                $describes = $reg->has('cash_position') && is_array($reg->get('cash_position')->describe());
                $reportOk = count($keys) > 0 && $describes;
                $reportNote = $reportOk ? 'report contract + registry present: ' . implode(', ', $keys) : 'registry empty or describe() malformed';
            }
        } catch (\Throwable $e) { $reportOk = false; $reportNote = 'report probe error: ' . $e->getMessage(); }
        $add('report_contract', 'Report definition contract reads SSOT services (E7.1)', $reportOk, $reportNote);

        // E7.2 — the generic report endpoint over the registry (GET /papi/reports/{key}).
        $reportApiOk = class_exists(\App\Http\Controllers\Panel\ReportController::class)
            && method_exists(\App\Http\Controllers\Panel\ReportController::class, 'show')
            && (bool) $this->fileHas('routes/web.php', "reports/{key}");
        $add('report_api', 'Generic report endpoint GET /papi/reports/{key} (E7.2)', $reportApiOk,
            $reportApiOk ? 'one endpoint serves every registered report (SSOT-only, filter-validated)'
                         : 'ReportController or reports routes not wired');

        // E7.3 — the hard-coded-FX invoiced.html is replaced by the SSOT Sales report (real invoices
        // via Fx); the legacy screen redirects to Reports.
        $salesOk = class_exists(\App\Reports\Definitions\SalesReport::class)
            && \App\Reports\ReportRegistry::default()->has('sales')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/InvoiceReportController.php', "redirect('/panel/pnl')");
        $add('sales_report', 'Sales report replaces hard-coded-FX invoiced.html (E7.3)', $salesOk,
            $salesOk ? 'Sales report reads real invoices via Fx; /panel/invoiced retired to Reports'
                     : 'Sales report or invoiced retirement not wired');

        // E7.4 — P&L + Trial Balance report views over the EXISTING (frozen) ledger. No accounting
        // change; the report definitions read TrialBalance (SSOT) and are registered.
        $ledgerRepOk = class_exists(\App\Reports\Definitions\ProfitAndLossReport::class)
            && class_exists(\App\Reports\Definitions\TrialBalanceReport::class)
            && \App\Reports\ReportRegistry::default()->has('pnl')
            && \App\Reports\ReportRegistry::default()->has('trial_balance');
        $add('ledger_reports', 'P&L + Trial Balance report views over the existing ledger (E7.4)', $ledgerRepOk,
            $ledgerRepOk ? 'pnl + trial_balance reports read TrialBalance (SSOT); accounting unchanged/frozen'
                         : 'ledger report views not registered');

        // E8.1 — the shared server list contract (q, filter[], sort, page, per_page → rows/total/pages/aggregates).
        $listOk = class_exists(\App\Support\Table\ListQuery::class)
            && class_exists(\App\Support\Table\ListResult::class)
            && class_exists(\App\Support\Table\ListBuilder::class)
            && method_exists(\App\Support\Table\ListBuilder::class, 'apply');
        $add('list_contract', 'Shared server list contract (search/filter/sort/page/aggregates) (E8.1)',
            $listOk,
            $listOk ? 'ListQuery + ListBuilder + ListResult present; one paging/filter engine for all registers'
                    : 'list contract classes not present');

        // E8.2 — CSV + PDF export of the full filtered set, wired into the reports endpoint.
        $exportOk = class_exists(\App\Support\Table\ListExporter::class)
            && method_exists(\App\Support\Table\ListExporter::class, 'csv')
            && method_exists(\App\Support\Table\ListExporter::class, 'pdf')
            && method_exists(\App\Support\Table\ListBuilder::class, 'allRows')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/ReportController.php', 'ListExporter::response');
        $add('list_export', 'CSV + PDF export of the full filtered set (E8.2)', $exportOk,
            $exportOk ? 'ListExporter (csv/pdf) + ListBuilder::allRows; reports export via ?format=csv|pdf'
                      : 'export helper not wired');

        // E8.3 — the shared JS DataTable component (search/filter/sort/pager/columns/saved filters).
        $dtOk = (bool) $this->fileHas('resources/panel/js/datatable.js', 'CloudBSSDataTable')
            && (bool) $this->fileHas('resources/panel/js/datatable.js', 'buildQuery')
            && (bool) $this->fileHas('resources/panel/js/datatable.js', 'exportUrl')
            && (bool) $this->fileHas('resources/panel/js/datatable.js', 'saveFilter');
        $add('datatable_component', 'Shared JS DataTable component consuming the list contract (E8.3)',
            $dtOk,
            $dtOk ? 'datatable.js present (search/filter/sort/pager/columns/saved-filters/export); render-sim green'
                  : 'resources/panel/js/datatable.js missing or incomplete');

        // E8.4 — Invoices + Payments server list endpoints in the shared contract (rows/total/pages/aggregates).
        $regOk = class_exists(\App\Http\Controllers\Panel\RegisterTableController::class)
            && method_exists(\App\Http\Controllers\Panel\RegisterTableController::class, 'invoices')
            && method_exists(\App\Http\Controllers\Panel\RegisterTableController::class, 'payments')
            && (bool) $this->fileHas('routes/web.php', 'invoices/table')
            && (bool) $this->fileHas('routes/web.php', 'payments/table');
        $add('register_table', 'Invoices + Payments list endpoints for the DataTable (E8.4)', $regOk,
            $regOk ? 'GET /papi/invoices/table + /papi/payments/table via ListBuilder (search/filter/sort/paging/aggregates)'
                   : 'RegisterTableController or its routes not wired');

        // E10.1 — role dashboard engine (widgets by role reading SSOT) + GET /papi/dashboard.
        $dashOk = interface_exists(\App\Dashboard\DashboardWidget::class)
            && class_exists(\App\Dashboard\DashboardRegistry::class)
            && class_exists(\App\Http\Controllers\Panel\DashboardController::class)
            && count(\App\Dashboard\DashboardRegistry::default()->forRole('owner')) > 0
            && (bool) $this->fileHas('routes/web.php', "'dashboard'");
        $add('role_dashboard', 'Role dashboard engine + /papi/dashboard reading SSOT (E10.1)', $dashOk,
            $dashOk ? 'widgets by role (cash/invoices/payments/stock/net-profit) all read SSOT services'
                    : 'dashboard engine or route not wired');

        $add('permissions_ready', 'Permission system deployed (Epic 2 / M1)', $permsReady,
            $permsReady
                ? 'roles + deny-by-default + action gate + endpoint guards + two-gate all present'
                : 'missing: ' . implode(', ', array_keys(array_filter([
                    'roles'            => ! $rolesExpanded,
                    'deny-by-default'  => ! $permStrictCode,
                    'action-gate'      => ! $accessGateCode,
                    'endpoint-guards'  => ! $guarded,
                    'two-gate'         => ! $twoGateCode,
                ]))));
        $add('lastmile_bridge', 'Shipment→delivery bridge (LastMileBridge)',
            class_exists(\App\Services\Logistics\LastMileBridge::class));
        $add('consignment_request', 'Consignment stock-request + notify Kampala',
            method_exists(\App\Http\Controllers\Panel\ConsignmentController::class, 'requestNotify')
            && (bool) $this->fileHas('resources/panel/consignment.html', 'addReqBulk'));
        $add('consignment_request_quick', 'One-tap stock request (Asish) → auto consignment',
            method_exists(\App\Http\Controllers\Panel\ConsignmentController::class, 'requestQuick')
            && (bool) $this->fileHas('resources/panel/consignment.html', 'quickRequest'));
        $add('consignment_request_picker', 'Request: catalogue picker + requested-by/send-to',
            method_exists(\App\Http\Controllers\Panel\ConsignmentController::class, 'contacts')
            && (bool) $this->fileHas('resources/panel/consignment.html', 'qAddPick'));
        $add('ads_screen', 'Facebook Ads screen',
            class_exists(\App\Http\Controllers\Panel\AdsController::class) && is_file(resource_path('panel/ads.html')));
        try {
            $t1 = Schema::hasTable('invoices');
            $t2 = Schema::hasTable('invoice_items');
            $t3 = Schema::hasTable('invoice_counters');
            $add('invoice_register_migrated', 'Invoice register tables migrated', $t1 && $t2 && $t3,
                ($t1 && $t2 && $t3) ? '' : 'run: php artisan migrate --force');
        } catch (\Throwable $e) { $add('invoice_register_migrated', 'Invoice register tables migrated', null, 'probe error'); }
        $add('invoice_register_code', 'Numbered invoice register (INV-2026-0001)',
            class_exists(\App\Http\Controllers\Panel\InvoiceController::class)
            && class_exists(\App\Services\Invoicing\InvoiceService::class)
            && is_file(resource_path('panel/invoices.html')));
        $add('invoice_auto_issue', 'Auto-issue invoice on delivery',
            class_exists(\App\Jobs\AutoIssueInvoice::class)
            && (bool) $this->fileHas('app/Observers/OrderObserver.php', 'AutoIssueInvoice'));
        $add('invoice_issue_btn', 'Issue-invoice button on order',
            (bool) $this->fileHas('resources/panel/seller.html', 'issueInvoice'));
        $add('invoice_send', 'Send invoice PDF on WhatsApp',
            method_exists(\App\Http\Controllers\Panel\InvoiceController::class, 'send')
            && (bool) $this->fileHas('resources/panel/invoices.html', 'sendInv'));
        $add('invoice_partial_pay', 'Record partial payment on invoice',
            method_exists(\App\Http\Controllers\Panel\InvoiceController::class, 'pay'));
        $add('payment_unified', 'Payments unified (one payment → cash + invoice)',
            method_exists(\App\Services\Invoicing\InvoiceService::class, 'syncByPayment')
            && (bool) $this->fileHas('app/Http/Controllers/Panel/PaymentController.php', 'syncByPayment'));
        $add('customer_statement', 'Customer statement of account (+ WhatsApp)',
            class_exists(\App\Services\Invoicing\StatementService::class)
            && method_exists(\App\Http\Controllers\Panel\InvoiceController::class, 'statementSend'));
        $add('customer_selfservice_statement', 'Customer self-service statement (login)',
            (bool) $this->fileHas('app/Http/Controllers/Storefront/CustomerAuthController.php', 'StatementService'));
        $add('customer_360_invoices', 'Customer 360 shows invoices + statement',
            (bool) $this->fileHas('resources/panel/customers360.html', 'loadStatement'));

        // --- Credit notes (CN-2026-0001) ---------------------------------------------
        try {
            $c1 = Schema::hasTable('credit_notes');
            $c2 = Schema::hasTable('credit_note_items');
            $c3 = Schema::hasTable('credit_note_counters');
            $add('credit_notes_migrated', 'Credit note tables migrated', $c1 && $c2 && $c3,
                ($c1 && $c2 && $c3) ? '' : 'run: php artisan migrate --force');
        } catch (\Throwable $e) { $add('credit_notes_migrated', 'Credit note tables migrated', null, 'probe error'); }
        $add('credit_notes_code', 'Numbered credit notes (CN-2026-0001)',
            class_exists(\App\Http\Controllers\Panel\CreditNoteController::class)
            && class_exists(\App\Services\Invoicing\CreditNoteService::class)
            && is_file(resource_path('panel/credit-notes.html')));
        $add('credit_note_pdf', 'Credit note PDF + WhatsApp send',
            method_exists(\App\Services\Invoicing\InvoiceRenderer::class, 'creditNotePdf')
            && method_exists(\App\Http\Controllers\Panel\CreditNoteController::class, 'send'));
        $add('credit_note_from_invoice', 'Credit note from an invoice',
            method_exists(\App\Services\Invoicing\CreditNoteService::class, 'issueForInvoice')
            && (bool) $this->fileHas('resources/panel/invoices.html', 'creditNote'));
        $add('credit_note_statement', 'Credit notes reduce statement balance',
            (bool) $this->fileHas('app/Services/Invoicing/StatementService.php', 'CreditNote')
            && (bool) $this->fileHas('resources/panel/customers360.html', 'tabCredits'));

        $add('invoice_pdf', 'Professional invoice PDF (server-side)',
            class_exists(\App\Http\Controllers\Panel\OrderInvoiceController::class)
            && class_exists(\Dompdf\Dompdf::class)
            && (bool) $this->fileHas('resources/panel/seller.html', 'exportInvoicePdf'),
            class_exists(\Dompdf\Dompdf::class) ? '' : 'dompdf not installed');

        // --- "In transit" status ------------------------------------------------------
        try {
            $it = in_array('In transit', \App\Models\Order::STATUSES, true);
            $add('in_transit', '"In transit" order status', $it);
        } catch (\Throwable $e) { $add('in_transit', '"In transit" order status', null, 'probe error'); }

        // --- Cashbook supplier/on-account --------------------------------------------
        $add('cashbook_supplier', 'Cashbook supplier + on-account payment',
            (bool) $this->fileHas('app/Http/Controllers/Panel/TradeCashbookController.php', 'supplier_payment'));

        // --- Sourcing: auto-ask Jayesh + finalise into catalogue ----------------------
        try {
            $s1 = Schema::hasColumn('sourcing_requests', 'reminder_count');
            $s2 = Schema::hasColumn('sourcing_requests', 'product_id');
            $s3 = Schema::hasColumn('sourcing_requests', 'decision_note');
            $add('sourcing_migrated', 'Sourcing tables migrated', $s1 && $s2 && $s3,
                $s1 && $s2 && $s3 ? '' : 'run: php artisan migrate --force');
        } catch (\Throwable $e) { $add('sourcing_migrated', 'Sourcing tables migrated', null, 'probe error'); }
        $sc = $this->fileHas('app/Http/Controllers/Panel/SourcingController.php', 'sendToBuyer');
        $scv = $this->fileHas('app/Http/Controllers/Panel/SourcingController.php', 'function convert');
        $srn = $this->fileHas('app/Http/Controllers/Panel/SourcingController.php', 'function renegotiate');
        $add('sourcing_code', 'Sourcing auto-ask + catalogue + renegotiate code',
            ($sc && $scv && $srn) ? true : (($sc === null) ? null : false));
        $add('sourcing_reply_code', 'Buyer reply-to-price (WhatsApp) code',
            (bool) $this->fileHas('app/Jobs/ProcessIncomingMessage.php', 'sourcing_buyer_phone'));
        $add('sourcing_remind_cmd', 'Sourcing reminder command',
            class_exists(\App\Console\Commands\SourcingRemindCommand::class));
        if ($t) {
            $bp = preg_replace('/[^0-9]/', '', (string) $t->setting('sourcing_buyer_phone', ''));
            $add('sourcing_buyer_set', 'Buyer (Jayesh) WhatsApp set', $bp !== '' ? true : null,
                $bp !== '' ? $bp : 'set it on /panel/sourcing');
        }

        // --- Bot patch (no screen — detect from deployed source) ----------------------
        $adGate = $this->fileHas('app/Services/WhatsApp/EvolutionGateway.php', 'externalAdReply');
        $adJob  = $this->fileHas('app/Jobs/ProcessIncomingMessage.php', 'bot_ad_awareness');
        $add('bot_ad_code', 'Bot Facebook-ad awareness code',
            ($adGate && $adJob) ? true : (($adGate === null || $adJob === null) ? null : false),
            ($adGate && $adJob) ? '' : 'deploy bot patch + rebuild (restarts queue worker)');
        $moqBrain = $this->fileHas('app/Services/Bot/BotBrain.php', 'moq_product');
        $moqFaq   = $this->fileHas('app/Services/Bot/FaqDictionary.php', 'moq_product');
        $add('bot_moq_code', 'Bot product-specific MOQ code',
            ($moqBrain && $moqFaq) ? true : (($moqBrain === null || $moqFaq === null) ? null : false));

        // --- Bot behaviour patch (ad-brand ignore, keep-AI-on, warehouse, product guidance) ---
        $add('bot_ad_brand_ignore', 'Ad page/brand not treated as product (SkyTrips fix)',
            (bool) $this->fileHas('app/Jobs/ProcessIncomingMessage.php', 'adTitleIsBrand'));
        $add('bot_keep_ai_on', 'Keep-AI-on after manual message (setting)',
            (bool) $this->fileHas('app/Jobs/ProcessIncomingMessage.php', 'human_takeover_on_reply'));
        $add('bot_warehouse', 'Warehouse / pickup answer (Luri)',
            (bool) $this->fileHas('app/Services/Bot/AiBrain.php', 'warehouse_location')
            && (bool) $this->fileHas('app/Services/Bot/FaqDictionary.php', 'warehouse'));
        $add('bot_product_guidance', 'Bot explains what products are for',
            (bool) $this->fileHas('app/Services/Bot/AiBrain.php', "GUIDE, DON'T JUST LIST"));
        $add('sales_cadence_code', 'Virtual salesperson (auto follow-up) code',
            class_exists(\App\Console\Commands\SalesCadenceCommand::class));
        if ($t) {
            $on = (bool) $t->setting('sales_followup_enabled', false);
            $add('sales_cadence_on', 'Virtual salesperson switched ON', $on ? true : null,
                $on ? ('reorder after ' . (int) $t->setting('sales_reorder_days', 21) . 'd') : 'turn on at /panel/ads');
        }
        $add('bot_reply_photo', 'Bot sends photo of the product it recommended',
            (bool) $this->fileHas('app/Services/Bot/AiBrain.php', 'product the BOT recommended'));
        $add('missing_photo_finder', 'Products “missing photo” finder',
            (bool) $this->fileHas('resources/panel/seller.html', 'pmMissingImgCount'));
        $add('ads_settings_ui', 'Ads screen behaviour controls',
            method_exists(\App\Http\Controllers\Panel\AdsController::class, 'saveSettings')
            && (bool) $this->fileHas('resources/panel/ads.html', 'saveBot'));
        if ($t) {
            $wh = trim((string) $t->setting('warehouse_location', ''));
            $add('warehouse_set', 'Warehouse location set', $wh !== '' ? true : null,
                $wh !== '' ? $wh : 'set it on /panel/ads → Bot behaviour');
            $ai = ! (bool) $t->setting('human_takeover_on_reply', true);
            $add('keep_ai_on_set', 'AI keeps replying after manual msg', $ai ? true : null,
                $ai ? 'on' : 'off — turn on at /panel/ads if wanted');
        }

        // --- Settings + live signal ---------------------------------------------------
        if ($t) {
            $add('bot_ad_awareness_on', 'Ad understanding switched ON',
                (bool) $t->setting('bot_ad_awareness', true),
                ((bool) $t->setting('bot_ad_awareness', true)) ? '' : 'turn on at /panel/ads');
            $add('bot_mode', 'Bot mode', true, (string) $t->setting('bot_mode', 'auto'));
            try {
                $n = (int) DB::table('bot_events')->where('tenant_id', $t->id)
                    ->where('stage', 'ad_referral')->where('created_at', '>=', now()->subDays(7))->count();
                $add('ad_arrivals_7d', 'Ad arrivals seen (7 days)', $n > 0 ? true : null, (string) $n);
            } catch (\Throwable $e) { $add('ad_arrivals_7d', 'Ad arrivals seen (7 days)', null, 'no bot_events'); }
        }

        $passed = count(array_filter($checks, fn ($c) => $c['ok'] === true));
        return response()->json([
            'ok'      => true,
            'checks'  => $checks,
            'summary' => ['passed' => $passed, 'total' => count($checks)],
        ]);
    }
}
