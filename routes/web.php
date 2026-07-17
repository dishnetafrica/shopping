<?php

use App\Http\Controllers\Marketing\MarketingController;
use App\Http\Controllers\Panel\PanelApiController;
use App\Http\Controllers\Panel\PwaController;
use App\Http\Controllers\Panel\SellerPanelController;
use App\Http\Controllers\Panel\TaskApiController;
use App\Http\Controllers\Panel\FieldTaskController;
use App\Http\Controllers\Panel\TrackController;
use App\Http\Middleware\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

// Public CloudBSS marketing landing page. Shop owners log in via /app/login,
// operator via /admin/login (both linked from the page).
Route::get('/', [MarketingController::class, 'home']);

// Public customer order-tracking page (no auth — order id + secret token are the key).
Route::get('/papi/track', [TrackController::class, 'show']);

// Public self-serve signup — provision a new shop with no human in the loop. Needs 'web' for the
// session so the owner is logged straight in. papi/* is CSRF-exempt (see bootstrap/app.php).
Route::middleware('web')->group(function () {
    Route::get('/start',                 [\App\Http\Controllers\Panel\StartController::class, 'page']);
    Route::post('/papi/start-provision', [\App\Http\Controllers\Panel\StartController::class, 'provision']);
});
Route::get('/healthz', fn () => response()->json(\App\Support\BuildInfo::light()));
Route::get('/papi/pending', [\App\Http\Controllers\Panel\PendingController::class, 'show']);
Route::get('/r/{token}',     [\App\Http\Controllers\Panel\RiderTrackController::class, 'show']);
Route::post('/r/{token}/loc', [\App\Http\Controllers\Panel\RiderTrackController::class, 'loc']);

// Operations field channel — no-login, signed + expiring links (the link is the auth).
Route::middleware('signed')->group(function () {
    Route::get('/ops/t/{task}',                       [FieldTaskController::class, 'show'])->name('ops.field.show');
    Route::get('/ops/t/{task}/accept',                [FieldTaskController::class, 'accept'])->name('ops.field.accept');
    Route::match(['get','post'], '/ops/t/{task}/checklist', [FieldTaskController::class, 'checklist'])->name('ops.field.checklist');
    Route::match(['get','post'], '/ops/t/{task}/complete',  [FieldTaskController::class, 'complete'])->name('ops.field.complete');
    Route::match(['get','post'], '/ops/t/{task}/comment',   [FieldTaskController::class, 'comment'])->name('ops.field.comment');
});

// Public shipment custody pages (no login — the shipment token is the key).
Route::get('/t/{token}',        [\App\Http\Controllers\Panel\ShipmentTrackController::class, 'transporter']);
Route::post('/t/{token}/action',[\App\Http\Controllers\Panel\ShipmentTrackController::class, 'transporterAction']);
Route::post('/t/{token}/scan',  [\App\Http\Controllers\Panel\ShipmentTrackController::class, 'transporterScan']);
Route::post('/t/{token}/scan-confirm',[\App\Http\Controllers\Panel\ShipmentTrackController::class, 'transporterScanConfirm']);
Route::get('/a/{token}',        [\App\Http\Controllers\Panel\ShipmentTrackController::class, 'agent']);
Route::post('/a/{token}/action',[\App\Http\Controllers\Panel\ShipmentTrackController::class, 'agentAction']);
Route::post('/a/{token}/scan',  [\App\Http\Controllers\Panel\ShipmentTrackController::class, 'agentScan']);
Route::post('/a/{token}/scan-confirm',[\App\Http\Controllers\Panel\ShipmentTrackController::class, 'agentScanConfirm']);

// PWA assets (public — a manifest / service worker / icon must load without a session).
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest']);
Route::get('/sw.js',                [PwaController::class, 'sw']);
Route::get('/apple-touch-icon.png', fn () => app(PwaController::class)->icon('apple-touch-icon.png'));
Route::get('/icons/{name}',         [PwaController::class, 'icon']);

// The customer's existing Family Shopper seller UI, served verbatim behind the
// /app web session. (Filament business panel stays available at /app.)
Route::middleware(['web', 'auth', SetTenantFromUser::class, \App\Http\Middleware\ConfineTransportRole::class])->group(function () {
    // E9.1 — shared design tokens stylesheet (one palette/typography/spacing), served for every panel page.
    Route::get('/panel/shared/tokens.css', function () {
        $path = resource_path('panel/shared/tokens.css');
        abort_unless(is_file($path), 404);
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/css; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    });
    // E9.2 — shared UI helpers (toast / confirmDialog / error-code map).
    Route::get('/panel/shared/ui.js', function () {
        $path = resource_path('panel/shared/ui.js');
        abort_unless(is_file($path), 404);
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    });
    Route::get('/panel', [\App\Http\Controllers\Panel\LandingController::class, 'panel']);
    // Transport Register — full-screen mobile page behind login (Kampala → Juba movement ledger).
    Route::get('/transport', [\App\Http\Controllers\Transport\TransportController::class, 'page']);
    Route::get('/panel/app', [\App\Http\Controllers\Panel\LauncherController::class, 'page']);
    Route::match(['get', 'post'], '/panel/logout', [SellerPanelController::class, 'logout']);
    Route::get('/panel/m', [\App\Http\Controllers\Panel\LandingController::class, 'mobile']);
    Route::get('/panel/chats', [SellerPanelController::class, 'chats']);
    Route::get('/panel/cashbook', [SellerPanelController::class, 'cashbook']);
    Route::get('/panel/books', [\App\Http\Controllers\Panel\BooksController::class, 'page']);
    Route::get('/panel/cash-position', [\App\Http\Controllers\Panel\CashPositionController::class, 'page']);
    Route::get('/panel/book-cost', [\App\Http\Controllers\Panel\OrderCostController::class, 'page']);
    Route::get('/panel/purchases', [\App\Http\Controllers\Panel\OrderCostController::class, 'purchasePage']);
    Route::get('/panel/orders-costs', [\App\Http\Controllers\Panel\OrderCostController::class, 'ordersPage']);
    Route::get('/panel/approvals', [\App\Http\Controllers\Panel\OrderCostController::class, 'approvalsPage']);
    Route::get('/panel/expenses', [\App\Http\Controllers\Panel\ExpenseController::class, 'page']);
    Route::get('/panel/pnl', [\App\Http\Controllers\Panel\ExpenseController::class, 'pnlPage']);
    Route::get('/panel/trade-cashbook', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'page']);
    Route::get('/panel/consignment', [\App\Http\Controllers\Panel\ConsignmentController::class, 'page']);
    Route::get('/panel/ads', [\App\Http\Controllers\Panel\AdsController::class, 'page']);
    Route::get('/panel/buy-list', [\App\Http\Controllers\Panel\BuyListController::class, 'page']);
    Route::get('/panel/supplier-debt', [\App\Http\Controllers\Panel\SupplierController::class, 'page']);
    Route::get('/panel/suppliers', [\App\Http\Controllers\Panel\SupplierController::class, 'manage']);
    Route::get('/panel/audit', [\App\Http\Controllers\Panel\AccountingControlsController::class, 'page']);
    Route::get('/panel/partners', [\App\Http\Controllers\Panel\PartnerController::class, 'page']);
    Route::get('/panel/invoiced', [\App\Http\Controllers\Panel\InvoiceReportController::class, 'page']);
    Route::get('/panel/invoices', [\App\Http\Controllers\Panel\InvoiceController::class, 'page']);
    Route::get('/panel/credit-notes', [\App\Http\Controllers\Panel\CreditNoteController::class, 'page']);
    Route::get('/panel/payments', [\App\Http\Controllers\Panel\PaymentController::class, 'page']);
    Route::get('/panel/payments-manager', [\App\Http\Controllers\Panel\PaymentsManagerController::class, 'page']);
    Route::get('/panel/sourcing', [\App\Http\Controllers\Panel\SourcingController::class, 'page']);
    Route::get('/panel/customers360', [\App\Http\Controllers\Panel\CustomerController::class, 'page']);
    Route::get('/panel/staff-cash', [\App\Http\Controllers\Panel\StaffCashController::class, 'page']);
    Route::get('/panel/my-cash', [\App\Http\Controllers\Panel\StaffCashController::class, 'myPage']);
    Route::get('/panel/ledger', [\App\Http\Controllers\Panel\LedgerController::class, 'page']);
    Route::get('/panel/staff', [SellerPanelController::class, 'staff']);
    Route::get('/panel/scheduled', [SellerPanelController::class, 'scheduled']);
    Route::get('/panel/marketing', [SellerPanelController::class, 'marketing']);
    Route::get('/panel/tasks', [SellerPanelController::class, 'tasks']);
    Route::get('/panel/operations', [SellerPanelController::class, 'tasks']);
    Route::get('/panel/diagnostics', [SellerPanelController::class, 'diagnostics']);
    Route::get('/panel/leads', [SellerPanelController::class, 'leads']);
    Route::get('/panel/setup', [SellerPanelController::class, 'setup']);
    Route::get('/panel/onboard', [SellerPanelController::class, 'onboard']);
    Route::get('/panel/system', [SellerPanelController::class, 'system']);
    Route::get('/panel/today', [SellerPanelController::class, 'today']);
    Route::get('/panel/inventory-count', [SellerPanelController::class, 'inventory']);
    Route::get('/panel/guide', [SellerPanelController::class, 'guide']);
    Route::get('/panel/shared/{file}', [SellerPanelController::class, 'shared'])->where('file', '[a-z]+\.js');
    Route::get('/panel/billing', [\App\Http\Controllers\Billing\BillingController::class, 'page']);

    // JSON API the panel calls. GET for everything (the panel uses query-string
    // writes); upload-image is POST. Tenant scoping is automatic.
    Route::prefix('papi')->group(function () {
        Route::prefix('task')->group(function () {
            Route::get('summary', [TaskApiController::class, 'summary']);
            Route::get('mine',  [TaskApiController::class, 'mine']);
            Route::get('queue', [TaskApiController::class, 'queue']);
            Route::match(['get','post'], 'complete',  [TaskApiController::class, 'complete']);
            Route::match(['get','post'], 'exception', [TaskApiController::class, 'exception']);
            Route::match(['get','post'], 'resume',    [TaskApiController::class, 'resume']);
            Route::match(['get','post'], 'checklist', [TaskApiController::class, 'checklist']);
            Route::match(['get','post'], 'comment',   [TaskApiController::class, 'comment']);
            Route::get('timeline', [TaskApiController::class, 'timeline']);
            Route::get('activity', [TaskApiController::class, 'activity']);
            Route::get('roles',    [TaskApiController::class, 'roles']);
            Route::match(['get','post'], 'roles-save', [TaskApiController::class, 'rolesSave']);
        });
        // auth (no-op; session already established)
        Route::get('auth-request',  [PanelApiController::class, 'authRequest']);
        Route::get('auth-verify',   [PanelApiController::class, 'authVerify']);

        // first-login setup wizard save
        Route::match(['get', 'post'], 'onboard-save', [PanelApiController::class, 'onboardSave']);

        // in-app AI help assistant
        Route::match(['get', 'post'], 'help-ask', [PanelApiController::class, 'helpAsk']);

        // first-run activation checklist
        Route::get('activation', [PanelApiController::class, 'activationState']);
        Route::match(['get', 'post'], 'activation/mark', [PanelApiController::class, 'activationMark']);
        Route::match(['get', 'post'], 'starter-catalogue', [PanelApiController::class, 'starterCatalogue']);

        // Books (accounting) — data + enable + statement
        Route::get('books', [\App\Http\Controllers\Panel\BooksController::class, 'data']);
        Route::match(['get','post'], 'books/enable', [\App\Http\Controllers\Panel\BooksController::class, 'enable']);
        Route::match(['get','post'], 'books/statement', [\App\Http\Controllers\Panel\BooksController::class, 'statement']);

        // Cash position + order costs (three-currency)
        Route::get('cash-position', [\App\Http\Controllers\Panel\CashPositionController::class, 'data']);
        Route::get('me/nav', [\App\Http\Controllers\Panel\OrderCostController::class, 'nav']);
        // E1.1 — single config-driven grouped menu (Product Architecture v2 §1). Read-only.
        Route::get('nav', [\App\Http\Controllers\Panel\NavController::class, 'nav']);
        // E3.3 — canonical exchange rate from the single FX service. Read-only.
        Route::get('fx', [\App\Http\Controllers\Panel\FxController::class, 'rates']);
        Route::get('order-costs/orders', [\App\Http\Controllers\Panel\OrderCostController::class, 'orders']);
        Route::post('order-costs', [\App\Http\Controllers\Panel\OrderCostController::class, 'store']);
        Route::post('order-costs/bulk', [\App\Http\Controllers\Panel\OrderCostController::class, 'bulkStore']);
        Route::get('buy-list', [\App\Http\Controllers\Panel\BuyListController::class, 'data']);
        Route::post('buy-list/mark', [\App\Http\Controllers\Panel\BuyListController::class, 'mark']);
        Route::get('suppliers', [\App\Http\Controllers\Panel\SupplierController::class, 'index']);
        Route::post('suppliers', [\App\Http\Controllers\Panel\SupplierController::class, 'store']);
        Route::get('purchases', [\App\Http\Controllers\Panel\OrderCostController::class, 'purchaseList']);
        Route::get('audit-log', [\App\Http\Controllers\Panel\AccountingControlsController::class, 'auditList']);
        Route::get('period-lock', [\App\Http\Controllers\Panel\AccountingControlsController::class, 'periodStatus']);
        Route::post('period-lock', [\App\Http\Controllers\Panel\AccountingControlsController::class, 'periodLock']);
        Route::get('integrity-check', [\App\Http\Controllers\Panel\AccountingControlsController::class, 'integrity']);
        Route::post('purchases', [\App\Http\Controllers\Panel\OrderCostController::class, 'purchaseStore']);
        Route::get('suppliers/debt', [\App\Http\Controllers\Panel\SupplierController::class, 'debt']);
        Route::post('suppliers/{id}/pay', [\App\Http\Controllers\Panel\SupplierController::class, 'pay']);
        Route::get('suppliers/{id}', [\App\Http\Controllers\Panel\SupplierController::class, 'show']);
        Route::post('suppliers/{id}/delete', [\App\Http\Controllers\Panel\SupplierController::class, 'destroy']);
        Route::post('suppliers/{id}', [\App\Http\Controllers\Panel\SupplierController::class, 'update']);
        Route::get('partners', [\App\Http\Controllers\Panel\PartnerController::class, 'index']);
        Route::post('partners', [\App\Http\Controllers\Panel\PartnerController::class, 'store']);
        Route::post('partners/{id}/pct', [\App\Http\Controllers\Panel\PartnerController::class, 'setPct']);
        Route::post('partners/{id}/capital', [\App\Http\Controllers\Panel\PartnerController::class, 'capital']);
        Route::post('partners/distribute', [\App\Http\Controllers\Panel\PartnerController::class, 'distribute']);
        Route::get('payments', [\App\Http\Controllers\Panel\PaymentController::class, 'index']);
        Route::get('payments/table', [\App\Http\Controllers\Panel\RegisterTableController::class, 'payments']); // E8.4 — before {id}
        Route::post('payments', [\App\Http\Controllers\Panel\PaymentController::class, 'store']);
        Route::get('payments/{id}', [\App\Http\Controllers\Panel\PaymentController::class, 'show']);
        Route::post('payments/{id}/apply', [\App\Http\Controllers\Panel\PaymentController::class, 'apply']);
        Route::post('payments/{id}/update', [\App\Http\Controllers\Panel\PaymentController::class, 'update']);
        Route::post('payments/{id}/delete', [\App\Http\Controllers\Panel\PaymentController::class, 'destroy']);
        Route::get('sourcing', [\App\Http\Controllers\Panel\SourcingController::class, 'index']);
        Route::post('sourcing', [\App\Http\Controllers\Panel\SourcingController::class, 'store']);
        Route::post('sourcing/{id}/price', [\App\Http\Controllers\Panel\SourcingController::class, 'price']);
        Route::post('sourcing/{id}/status', [\App\Http\Controllers\Panel\SourcingController::class, 'status']);
        Route::get('sourcing/buyer',  [\App\Http\Controllers\Panel\SourcingController::class, 'buyerGet']);
        Route::post('sourcing/buyer', [\App\Http\Controllers\Panel\SourcingController::class, 'buyerSave']);
        Route::post('sourcing/{id}/remind', [\App\Http\Controllers\Panel\SourcingController::class, 'remind']);
        Route::post('sourcing/{id}/convert', [\App\Http\Controllers\Panel\SourcingController::class, 'convert']);
        Route::post('sourcing/{id}/reject', [\App\Http\Controllers\Panel\SourcingController::class, 'reject']);
        Route::post('sourcing/{id}/renegotiate', [\App\Http\Controllers\Panel\SourcingController::class, 'renegotiate']);
        Route::get('staff-cash', [\App\Http\Controllers\Panel\StaffCashController::class, 'people']);
        Route::get('staff-cash/ledger', [\App\Http\Controllers\Panel\StaffCashController::class, 'ledger']);
        Route::post('ledger/rebuild', [\App\Http\Controllers\Panel\LedgerController::class, 'rebuild']);
        Route::get('ledger/trial-balance', [\App\Http\Controllers\Panel\LedgerController::class, 'trialBalance']);
        Route::get('ledger/balance-sheet', [\App\Http\Controllers\Panel\LedgerController::class, 'balanceSheet']);
        Route::get('ledger/pnl', [\App\Http\Controllers\Panel\LedgerController::class, 'pnl']);
        Route::get('ledger/gl', [\App\Http\Controllers\Panel\LedgerController::class, 'generalLedger']);
        Route::get('ledger/status', [\App\Http\Controllers\Panel\LedgerController::class, 'status']);
        Route::post('ledger/live', [\App\Http\Controllers\Panel\LedgerController::class, 'toggleLive']);
        Route::get('ledger/accounts', [\App\Http\Controllers\Panel\LedgerController::class, 'accounts']);
        Route::post('ledger/manual', [\App\Http\Controllers\Panel\LedgerController::class, 'manual']);
        Route::post('ledger/fx-recognize', [\App\Http\Controllers\Panel\LedgerController::class, 'fxRecognize']);
        Route::post('ledger/revalue', [\App\Http\Controllers\Panel\LedgerController::class, 'revalue']);
        Route::get('ledger/reconcile', [\App\Http\Controllers\Panel\LedgerController::class, 'reconcile']);
        Route::match(['get','post'],'ledger/lock', [\App\Http\Controllers\Panel\LedgerController::class, 'lock']);
        Route::post('staff-cash/give', [\App\Http\Controllers\Panel\StaffCashController::class, 'give']);
        Route::post('staff-cash/spend', [\App\Http\Controllers\Panel\StaffCashController::class, 'spend']);
        Route::post('staff-cash/receive', [\App\Http\Controllers\Panel\StaffCashController::class, 'receive']);
        Route::post('staff-cash/settle', [\App\Http\Controllers\Panel\StaffCashController::class, 'settle']);
        Route::post('orders/quick', [\App\Http\Controllers\Panel\OrderCostController::class, 'quickOrder']);
        Route::get('orders/{id}/costs', [\App\Http\Controllers\Panel\OrderCostController::class, 'orderDetail']);
        Route::get('order-costs/pending', [\App\Http\Controllers\Panel\OrderCostController::class, 'pending']);
        Route::post('order-costs/{id}/decide', [\App\Http\Controllers\Panel\OrderCostController::class, 'decide']);
        Route::post('order-costs/{id}/delete', [\App\Http\Controllers\Panel\OrderCostController::class, 'deleteCost']);
        Route::post('order-costs/{id}/update', [\App\Http\Controllers\Panel\OrderCostController::class, 'purchaseUpdate']);
        Route::post('orders/{id}/delete', [\App\Http\Controllers\Panel\OrderCostController::class, 'deleteOrder']);
        Route::get('expenses/categories', [\App\Http\Controllers\Panel\ExpenseController::class, 'categories']);
        Route::post('expenses', [\App\Http\Controllers\Panel\ExpenseController::class, 'store']);
        Route::get('pnl', [\App\Http\Controllers\Panel\ExpenseController::class, 'pnl']);
        Route::get('expenses/list', [\App\Http\Controllers\Panel\ExpenseController::class, 'list']);
        Route::post('expenses/{id}/delete', [\App\Http\Controllers\Panel\ExpenseController::class, 'deleteExpense']);
        Route::get('trade-cashbook', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'data']);
        Route::get('holder-balance', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'balanceFor']);
        Route::get('trade-cashbook/all', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'all']);
        Route::post('trade-cashbook', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'store']);
        Route::post('trade-cashbook/{id}/delete', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'delete']);
        Route::post('trade-cashbook/{id}/update', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'update']);
        Route::post('trade-cashbook/convert', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'convert']);
        Route::post('trade-cashbook/transfer', [\App\Http\Controllers\Panel\TradeCashbookController::class, 'transfer']);

        // in-product release notes
        Route::get('whatsnew', [PanelApiController::class, 'whatsNew']);

        // reads
        Route::get('orders',     [PanelApiController::class, 'orders']);
        Route::get('kitchen',         [PanelApiController::class, 'kitchen']);
        Route::get('kitchen-advance', [PanelApiController::class, 'kitchenAdvance']);
        Route::get('products',   [PanelApiController::class, 'products']);
        Route::get('riders',     [PanelApiController::class, 'riders']);
        Route::get('returns',    [PanelApiController::class, 'returns']);
        Route::get('today',      [PanelApiController::class, 'today']);   // Today / Needs-attention home screen
        Route::get('quotation-pdf', [PanelApiController::class, 'quotationPdf']); // stream PDF (no /storage symlink)
        Route::match(['get','post'], 'order-invoice-pdf', [\App\Http\Controllers\Panel\OrderInvoiceController::class, 'pdf']); // clean wholesale invoice PDF (WYSIWYG)
        // Numbered invoice register (INV-2026-0001)
        Route::get('invoices',                    [\App\Http\Controllers\Panel\InvoiceController::class, 'index']);
        Route::get('invoices/table', [\App\Http\Controllers\Panel\RegisterTableController::class, 'invoices']); // E8.4 — before any {id}
        Route::match(['get','post'], 'invoice-issue', [\App\Http\Controllers\Panel\InvoiceController::class, 'issue']);
        Route::get('invoice-show',                [\App\Http\Controllers\Panel\InvoiceController::class, 'show']);
        Route::get('invoice-pdf',                 [\App\Http\Controllers\Panel\InvoiceController::class, 'pdf']);
        Route::match(['get','post'], 'invoice-send', [\App\Http\Controllers\Panel\InvoiceController::class, 'send']);
        Route::match(['get','post'], 'invoice-pay',  [\App\Http\Controllers\Panel\InvoiceController::class, 'pay']);
        Route::match(['get','post'], 'invoice-void', [\App\Http\Controllers\Panel\InvoiceController::class, 'void']);
        // Numbered credit note register (CN-2026-0001)
        Route::get('credit-notes',                     [\App\Http\Controllers\Panel\CreditNoteController::class, 'index']);
        Route::match(['get','post'], 'credit-note-issue', [\App\Http\Controllers\Panel\CreditNoteController::class, 'issue']);
        Route::get('credit-note-show',                 [\App\Http\Controllers\Panel\CreditNoteController::class, 'show']);
        Route::get('credit-note-pdf',                  [\App\Http\Controllers\Panel\CreditNoteController::class, 'pdf']);
        Route::match(['get','post'], 'credit-note-send',  [\App\Http\Controllers\Panel\CreditNoteController::class, 'send']);
        Route::match(['get','post'], 'credit-note-void',  [\App\Http\Controllers\Panel\CreditNoteController::class, 'void']);
        Route::get('credit-note-invoices',             [\App\Http\Controllers\Panel\CreditNoteController::class, 'invoiceOptions']);
        // Customer statement of account
        Route::get('statement',      [\App\Http\Controllers\Panel\InvoiceController::class, 'statementData']);
        Route::get('statement-pdf',  [\App\Http\Controllers\Panel\InvoiceController::class, 'statementPdf']);
        Route::match(['get','post'], 'statement-send', [\App\Http\Controllers\Panel\InvoiceController::class, 'statementSend']);
        Route::get('inventory-list',  [PanelApiController::class, 'inventoryList']);
        Route::get('inventory-count', [PanelApiController::class, 'inventoryCount']);

        // Delivery Management V2 (D1/D2)
        Route::get('delivery/quote',         [\App\Http\Controllers\Panel\DeliveryController::class, 'quote']);
        Route::get('delivery/board',         [\App\Http\Controllers\Panel\DeliveryController::class, 'board']);
        Route::get('delivery/suggest-rider', [\App\Http\Controllers\Panel\DeliveryController::class, 'suggestRider']);
        Route::get('delivery/assign',       [\App\Http\Controllers\Panel\DeliveryController::class, 'assign']);
        Route::get('delivery/status',       [\App\Http\Controllers\Panel\DeliveryController::class, 'status']);

        // Owner-facing settings moved into the Seller Panel (no /app shell)
        Route::get('delivery/zones',         [\App\Http\Controllers\Panel\PanelOwnerController::class, 'zones']);
        Route::get('delivery/zone-save',    [\App\Http\Controllers\Panel\PanelOwnerController::class, 'zoneSave']);
        Route::get('delivery/zone-delete',  [\App\Http\Controllers\Panel\PanelOwnerController::class, 'zoneDelete']);
        Route::get('profile',                [\App\Http\Controllers\Panel\PanelOwnerController::class, 'profile']);
        Route::get('profile-save',          [\App\Http\Controllers\Panel\PanelOwnerController::class, 'profileSave']);
        Route::get('password-change',       [\App\Http\Controllers\Panel\PanelOwnerController::class, 'passwordChange']);
        Route::get('notifications',          [\App\Http\Controllers\Panel\PanelOwnerController::class, 'notifications']);
        Route::get('notif-save',            [\App\Http\Controllers\Panel\PanelOwnerController::class, 'notifSave']);
        Route::get('notif-delete',          [\App\Http\Controllers\Panel\PanelOwnerController::class, 'notifDelete']);
        Route::get('defaults',               [\App\Http\Controllers\Panel\PanelOwnerController::class, 'defaults']);
        Route::get('default-save',          [\App\Http\Controllers\Panel\PanelOwnerController::class, 'defaultSave']);
        Route::get('default-delete',        [\App\Http\Controllers\Panel\PanelOwnerController::class, 'defaultDelete']);
        Route::get('settings',   [PanelApiController::class, 'settings']);
        Route::get('image-stats', [PanelApiController::class, 'imageStats']);
        Route::get('operations',  [PanelApiController::class, 'operations']);
        Route::get('health',       [PanelApiController::class, 'health']);
        Route::get('platform',                 [PanelApiController::class, 'platformDiag']);
        Route::get('platform/errors',          [PanelApiController::class, 'platformErrors']);
        Route::match(['get', 'post'], 'resync-balances', [PanelApiController::class, 'resyncBalances']);
        Route::get('reconcile-audit', [PanelApiController::class, 'reconcileAudit']);
        Route::match(['get', 'post'], 'backfill-invoices', [PanelApiController::class, 'backfillInvoices']);
        Route::match(['get', 'post'], 'fix-cash-rates', [PanelApiController::class, 'fixCashRates']);
        Route::get('bot/quote-diagnostics', [PanelApiController::class, 'botQuoteDiagnostics']);
        Route::match(['get', 'post'], 'backfill-customers', [PanelApiController::class, 'backfillCustomers']);
        Route::match(['get', 'post'], 'platform/opcache-reset', [PanelApiController::class, 'opcacheReset']);

        // CRM — manual lead management (Build 86.5)
        Route::get('leads',        [PanelApiController::class, 'leadsList']);
        Route::get('lead-options', [PanelApiController::class, 'leadOptions']);
        Route::get('lead-save',    [PanelApiController::class, 'leadSave']);
        Route::get('lead-action',  [PanelApiController::class, 'leadAction']);
        Route::post('lead-import', [PanelApiController::class, 'leadImport']);
        Route::get('branches',   [PanelApiController::class, 'branches']);
        Route::get('customers',  [PanelApiController::class, 'customers']);
        Route::get('contacts-import', [PanelApiController::class, 'importContacts']);
        Route::get('bot-config', [PanelApiController::class, 'botConfig']);

        // live chats inbox (Phase 4b)
        Route::get('chats',              [PanelApiController::class, 'chats']);
        Route::get('chats/thread',       [PanelApiController::class, 'chatThread']);
        Route::post('chats/send',        [PanelApiController::class, 'chatSend']);
        Route::post('chats/takeover',    [PanelApiController::class, 'chatTakeover']);
        Route::post('chats/mute',        [PanelApiController::class, 'chatMute']);
        Route::post('chats/bot-mode',    [PanelApiController::class, 'chatBotMode']);
        Route::post('chats/sync',        [PanelApiController::class, 'chatSync']);
        Route::get('chats/sync-debug',    [PanelApiController::class, 'chatSyncDebug']);
        Route::post('chats/relink-webhook', [PanelApiController::class, 'chatRelinkWebhook']);

        // self-serve onboarding (WhatsApp QR connect + AI bot setup)
        Route::get('wa/status',      [PanelApiController::class, 'waStatus']);
        Route::post('wa/connect',    [PanelApiController::class, 'waConnect']);
        Route::get('wa/qr',          [PanelApiController::class, 'waQr']);
        Route::post('wa/disconnect', [PanelApiController::class, 'waDisconnect']);
        Route::get('wa/channels',    [PanelApiController::class, 'waChannels']);
        Route::post('wa/channels/add',    [PanelApiController::class, 'waChannelAdd']);
        Route::post('wa/channels/qr',     [PanelApiController::class, 'waChannelQr']);
        Route::post('wa/channels/remove', [PanelApiController::class, 'waChannelRemove']);
        Route::get('wa/cloud-info',    [PanelApiController::class, 'waCloudInfo']);
        Route::post('wa/cloud-save',   [PanelApiController::class, 'waCloudSave']);
        Route::post('wa/use-evolution',[PanelApiController::class, 'waUseEvolution']);

        // cashbook + order payments
        Route::get('cashbook',          [PanelApiController::class, 'cashbook']);
        Route::post('cashbook/add',     [PanelApiController::class, 'cashbookAdd']);
        Route::post('record-payment',   [PanelApiController::class, 'recordPayment']);

        // staff logins (seat-capped by plan)
        Route::get('staff',          [PanelApiController::class, 'staffList']);
        Route::post('staff/add',     [PanelApiController::class, 'staffAdd']);
        Route::post('staff/update',  [PanelApiController::class, 'staffUpdate']);
        Route::post('staff/delete',  [PanelApiController::class, 'staffDelete']);

        // scheduled deliveries
        Route::get('scheduled',          [PanelApiController::class, 'scheduledList']);
        Route::post('schedule-order',    [PanelApiController::class, 'scheduleOrder']);

        // marketing campaigns
        Route::get('campaigns',          [PanelApiController::class, 'campaignList']);
        Route::post('campaign/save',     [PanelApiController::class, 'campaignSave']);
        Route::post('campaign/send',     [PanelApiController::class, 'campaignSend']);
        Route::post('campaign/audience', [PanelApiController::class, 'campaignAudience']);
        Route::post('campaign/suggest',  [PanelApiController::class, 'campaignSuggest']);

        // bot pipeline diagnostics
        Route::get('diagnostics',        [PanelApiController::class, 'diagnostics']);
        Route::post('bot/generate',  [PanelApiController::class, 'botGenerate']);
        Route::post('bot/save',      [PanelApiController::class, 'botSave']);

        // writes that persist
        Route::get('update-status',  [PanelApiController::class, 'updateStatus']);
        Route::get('save-order',     [PanelApiController::class, 'saveOrder']);
        Route::match(['get', 'post'], 'quotation-send', [PanelApiController::class, 'quotationSend']);
        Route::get('quotations', [PanelApiController::class, 'quotations']);
        Route::match(['get', 'post'], 'quotation-resend',  [PanelApiController::class, 'quotationResend']);
        Route::match(['get', 'post'], 'quotation-status',  [PanelApiController::class, 'quotationStatus']);
        Route::match(['get', 'post'], 'quotation-convert', [PanelApiController::class, 'quotationConvert']);
        Route::get('quotation-show', [PanelApiController::class, 'quotationShow']);
        Route::match(['get', 'post'], 'quotation-update', [PanelApiController::class, 'quotationUpdate']);
        // Sales-side edit that creates an immutable revision (instead of overwriting) + notifies the customer.
        Route::post('quotation-revise', [\App\Http\Controllers\Panel\QuotationReviseController::class, 'revise']);
        // Deterministic sales intelligence for a quotation (stock/alternatives/usual/volume-discount).
        Route::get('quotation-intel', [\App\Http\Controllers\Panel\QuotationIntelController::class, 'intel']);
        Route::match(['get', 'post'], 'quotation-delete', [PanelApiController::class, 'quotationDelete']);
        // One-shot reseed of categories.sort so the storefront department list is food-first.
        Route::post('category-reseed', [\App\Http\Controllers\Panel\CategoryOrderController::class, 'reseed']);
        // Business-Type banners (Shop by Business merchandising) — read + save config.
        Route::get('business-banners', [PanelApiController::class, 'bannersGet']);
        Route::post('business-banners-save', [PanelApiController::class, 'bannersSave']);
        // Ready-made Kits (curated bundles → one-tap quotation) — read + save.
        Route::get('kits', [PanelApiController::class, 'kitsGet']);
        Route::post('kits-save', [PanelApiController::class, 'kitsSave']);
        // Delivery: per-city free-delivery thresholds — read + save.
        Route::get('delivery-rules', [PanelApiController::class, 'deliveryGet']);
        Route::post('delivery-rules-save', [PanelApiController::class, 'deliverySave']);
        // Transport Register (Kampala → Juba) — offline-first sync (merge by uid/updatedAt).
        Route::get('transport-get', [\App\Http\Controllers\Transport\TransportController::class, 'get']);
        Route::post('transport-sync', [\App\Http\Controllers\Transport\TransportController::class, 'sync']);
        // Sales currency basis (native = base currency is the operating currency, e.g. USD).
        Route::get('currency-basis', [PanelApiController::class, 'currencyBasisGet']);
        Route::post('currency-basis-save', [PanelApiController::class, 'currencyBasisSave']);
        Route::get('update-product', [PanelApiController::class, 'updateProduct']);
        Route::get('delete-product', [PanelApiController::class, 'deleteProduct']);
        Route::get('add-product',    [PanelApiController::class, 'addProduct']);
        Route::post('ai-identify',   [PanelApiController::class, 'aiIdentify']);
        Route::post('products-import', [PanelApiController::class, 'productsImport']);
        Route::post('photos-import',   [PanelApiController::class, 'photosImport']);
        Route::post('products-restore', [PanelApiController::class, 'productsRestore']);
        Route::get('product-bulk-meta', [PanelApiController::class, 'productBulkMeta']);
        Route::post('stock-sync', [PanelApiController::class, 'stockSync']);
        Route::post('stock/reconcile-cache', [PanelApiController::class, 'stockReconcileCache']); // E6.5
        Route::get('reports', [\App\Http\Controllers\Panel\ReportController::class, 'index']);       // E7.2
        Route::get('reports/{key}', [\App\Http\Controllers\Panel\ReportController::class, 'show']);  // E7.2
        Route::get('dashboard', [\App\Http\Controllers\Panel\DashboardController::class, 'index']);  // E10.1
        Route::post('images-borrow', [PanelApiController::class, 'imagesBorrow']);
        Route::post('upload-image',  [PanelApiController::class, 'uploadImage']);
        Route::post('upload-doc',    [PanelApiController::class, 'uploadDoc']);
        Route::get('category-images',     [PanelApiController::class, 'categoryImages']);
        Route::post('category-image-save',[PanelApiController::class, 'categoryImageSave']);
        Route::post('category-create',    [PanelApiController::class, 'categoryCreate']);
        Route::post('category-rename',    [PanelApiController::class, 'categoryRename']);
        Route::post('category-delete',    [PanelApiController::class, 'categoryDelete']);
        Route::post('category-reorder',   [PanelApiController::class, 'categoryReorder']);
        Route::post('product-order',       [PanelApiController::class, 'productOrder']);
        Route::get('analytics',            [PanelApiController::class, 'analytics']);
        Route::get('analytics-csv',        [PanelApiController::class, 'analyticsCsv']);
        Route::get('thali',               [PanelApiController::class, 'thaliGet']);
        Route::post('thali-save',         [PanelApiController::class, 'thaliSave']);

        // writes wired in Phase 3b (return ok:false -> panel saves locally for now)
        Route::get('dispatch',     [PanelApiController::class, 'dispatch']);
        Route::get('rider-save',   [PanelApiController::class, 'riderSave']);
        Route::get('rider-delete', [PanelApiController::class, 'riderDel']);
        Route::get('return',          [PanelApiController::class, 'returnSave']);
        Route::get('settings-save',   [PanelApiController::class, 'settingsSave']);
        Route::match(['get','post'],'order-system', [PanelApiController::class, 'orderSystemToggle']);
        Route::get('reengage-save',   [PanelApiController::class, 'reengageSave']);
        Route::get('credit-reminder-save', [PanelApiController::class, 'creditReminderSave']);
        Route::post('branding-save',  [PanelApiController::class, 'brandingSave']);
        Route::get('bot-toggle',      [PanelApiController::class, 'botToggle']);

        // Status Intelligence — Activity Review Inbox (v17)
        Route::get('activity-inbox',   [PanelApiController::class, 'activityInbox']);
        Route::get('activity-approve', [PanelApiController::class, 'activityApprove']);
        Route::get('activity-reject',  [PanelApiController::class, 'activityReject']);
        Route::get('activity-edit',    [PanelApiController::class, 'activityEdit']);
        Route::get('brain',            [PanelApiController::class, 'brainData']);
        Route::get('brain-discover',   [PanelApiController::class, 'brainDiscover']);
        Route::get('brain-candidates',        [PanelApiController::class, 'brainCandidates']);
        Route::get('brain-candidate-approve', [PanelApiController::class, 'brainCandidateApprove']);
        Route::get('brain-candidate-dismiss', [PanelApiController::class, 'brainCandidateDismiss']);

        // Shipment Platform v2A — seller-panel logistics (no token pages / notifications yet)
        Route::get('shipments',                [\App\Http\Controllers\Panel\ShipmentController::class, 'index']);
        Route::get('shipments-dashboard',      [\App\Http\Controllers\Panel\ShipmentController::class, 'dashboard']);
        Route::get('shipment',                 [\App\Http\Controllers\Panel\ShipmentController::class, 'show']);
        Route::get('shipment-create',          [\App\Http\Controllers\Panel\ShipmentController::class, 'store']);
        Route::get('shipment-action',          [\App\Http\Controllers\Panel\ShipmentController::class, 'action']);
        Route::get('shipment-exception-resolve',[\App\Http\Controllers\Panel\ShipmentController::class, 'resolveException']);
        Route::get('order-shipment',           [\App\Http\Controllers\Panel\ShipmentController::class, 'forOrder']);
        Route::get('shipment-handoff',         [\App\Http\Controllers\Panel\ShipmentController::class, 'handoff']);
        Route::get('shipment-auto-toggle',     [\App\Http\Controllers\Panel\ShipmentController::class, 'autoToggle']);
        Route::get('shipment-labels',          [\App\Http\Controllers\Panel\ShipmentController::class, 'labels']);
        Route::get('shipment-manifest',        [\App\Http\Controllers\Panel\ShipmentController::class, 'manifest']);
        // Consignment (one AWB, many orders) — Kampala -> Juba daily consolidation
        Route::get('consignments',                 [\App\Http\Controllers\Panel\ConsignmentController::class, 'index']);
        Route::get('consignment',                  [\App\Http\Controllers\Panel\ConsignmentController::class, 'show']);
        Route::get('consignment-candidates',       [\App\Http\Controllers\Panel\ConsignmentController::class, 'candidates']);
        Route::match(['get','post'], 'consignment-create',      [\App\Http\Controllers\Panel\ConsignmentController::class, 'create']);
        Route::match(['get','post'], 'consignment-add-order',   [\App\Http\Controllers\Panel\ConsignmentController::class, 'addOrder']);
        Route::match(['get','post'], 'consignment-remove-order',[\App\Http\Controllers\Panel\ConsignmentController::class, 'removeOrder']);
        Route::match(['get','post'], 'consignment-add-all',     [\App\Http\Controllers\Panel\ConsignmentController::class, 'addAll']);
        Route::match(['get','post'], 'consignment-deliver-all', [\App\Http\Controllers\Panel\ConsignmentController::class, 'deliverAll']);
        Route::match(['get','post'], 'consignment-line-status', [\App\Http\Controllers\Panel\ConsignmentController::class, 'setLineStatus']);
        Route::get('consignment-carry-source',     [\App\Http\Controllers\Panel\ConsignmentController::class, 'carrySource']);
        Route::match(['get','post'], 'consignment-stock-add',    [\App\Http\Controllers\Panel\ConsignmentController::class, 'addStock']);
        Route::match(['get','post'], 'consignment-stock-update', [\App\Http\Controllers\Panel\ConsignmentController::class, 'updateStock']);
        Route::match(['get','post'], 'consignment-stock-remove', [\App\Http\Controllers\Panel\ConsignmentController::class, 'removeStock']);
        Route::match(['get','post'], 'consignment-stock-sell',   [\App\Http\Controllers\Panel\ConsignmentController::class, 'sellStock']);
        Route::match(['get','post'], 'consignment-notify',       [\App\Http\Controllers\Panel\ConsignmentController::class, 'notify']);
        Route::match(['get','post'], 'consignment-request-notify',[\App\Http\Controllers\Panel\ConsignmentController::class, 'requestNotify']);
        Route::match(['get','post'], 'consignment-request-quick', [\App\Http\Controllers\Panel\ConsignmentController::class, 'requestQuick']);
        Route::get('consignment-contacts', [\App\Http\Controllers\Panel\ConsignmentController::class, 'contacts']);
        Route::get('consignment-sheet',            [\App\Http\Controllers\Panel\ConsignmentController::class, 'sheet']);
        // Ads (Facebook-ad bot understanding: toggle + activity)
        Route::get('ads',                          [\App\Http\Controllers\Panel\AdsController::class, 'data']);
        Route::match(['get','post'], 'ads-toggle', [\App\Http\Controllers\Panel\AdsController::class, 'toggle']);
        Route::match(['get','post'], 'ads-settings', [\App\Http\Controllers\Panel\AdsController::class, 'saveSettings']);
        Route::get('selfcheck', [\App\Http\Controllers\Panel\SelfcheckController::class, 'run']);
        Route::get('bot-config-save', [PanelApiController::class, 'botConfigSave']);
        Route::get('branch-save',     [PanelApiController::class, 'branchSave']);
        Route::get('branch-delete',   [PanelApiController::class, 'branchDel']);
        Route::get('customer-save',   [PanelApiController::class, 'customerSave']);

        // billing / upgrade (Phase 13)
        Route::get('billing/quote',    [\App\Http\Controllers\Billing\BillingController::class, 'quote']);
        Route::post('billing/pay-momo',[\App\Http\Controllers\Billing\BillingController::class, 'payMomo']);
        Route::post('billing/pay-card',[\App\Http\Controllers\Billing\BillingController::class, 'payCard']);
        Route::get('billing/status',   [\App\Http\Controllers\Billing\BillingController::class, 'status']);
    });
});

/*
|--------------------------------------------------------------------------
| Public customer storefront — mycloudbss.com/{shop}
|--------------------------------------------------------------------------
| One storefront per tenant, resolved by slug. Registered LAST so it never
| shadows '/', '/panel', or Filament's '/app' and '/admin'. The slug regex
| also excludes reserved single-segment paths via a negative lookahead, so
| even if route order changed, /app & /admin would fall through to Filament.
*/
$shopSlug = '(?!(app|admin|panel|papi|api|storage|livewire|build|vendor|up|login|logout|register|start|q|quote)$)[a-z0-9][a-z0-9\-]*';
$qToken   = '[a-z0-9]{40}';

Route::middleware('web')->group(function () use ($qToken) {
    // Custom-domain storefront: the SAME shop served at the ROOT of the tenant's own domain
    // (e.g. https://familyshoppers.net/), resolved by Host. shop=null → the controller resolves by
    // custom_domain. Registered before the slug routes so single-segment paths bind here; on the
    // platform host they 404 (no tenant claims the platform host), so they never shadow real pages.
    Route::get('/product/{product}', [\App\Http\Controllers\Storefront\StorefrontProductController::class, 'show'])->where('product', '[0-9]+');
    Route::get('/shop',            [\App\Http\Controllers\Storefront\StorefrontController::class, 'show']);
    Route::get('/catalogue',       [\App\Http\Controllers\Storefront\StorefrontController::class, 'catalogue']);
    Route::post('/order',          [\App\Http\Controllers\Storefront\StorefrontController::class, 'placeOrder']);
    // Quotation-first flow (B2B): submit cart -> instant Quotation + public portal.
    Route::post('/quote',          [\App\Http\Controllers\Storefront\StorefrontController::class, 'requestQuote']);
    Route::get('/q/{token}',         [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'show'])->where('token', $qToken);
    Route::get('/q/{token}/data',    [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'data'])->where('token', $qToken);
    Route::get('/q/{token}/pdf',     [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'pdf'])->where('token', $qToken);
    Route::post('/q/{token}/share',  [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'share'])->where('token', $qToken);
    Route::post('/q/{token}/approve',[\App\Http\Controllers\Storefront\QuotationPortalController::class, 'approve'])->where('token', $qToken);
    Route::post('/q/{token}/revise', [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'revise'])->where('token', $qToken);
    Route::post('/event',          [\App\Http\Controllers\Storefront\StorefrontController::class, 'recordEvent']);
    Route::post('/auth/request',   [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'request']);
    Route::post('/auth/verify',    [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'verify']);
    Route::post('/account/orders', [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'myOrders']);
    Route::post('/account/profile',[\App\Http\Controllers\Storefront\CustomerAuthController::class, 'saveProfile']);
});

Route::middleware('web')->group(function () use ($shopSlug, $qToken) {
    Route::get('/{shop}/product/{product}', [\App\Http\Controllers\Storefront\StorefrontProductController::class, 'show'])->where('shop', $shopSlug)->where('product', '[0-9]+');
    Route::get('/{shop}',           [\App\Http\Controllers\Storefront\StorefrontController::class, 'landing'])->where('shop', $shopSlug);
    Route::get('/{shop}/shop',      [\App\Http\Controllers\Storefront\StorefrontController::class, 'show'])->where('shop', $shopSlug);
    Route::get('/{shop}/catalogue', [\App\Http\Controllers\Storefront\StorefrontController::class, 'catalogue'])->where('shop', $shopSlug);
    Route::post('/{shop}/order',    [\App\Http\Controllers\Storefront\StorefrontController::class, 'placeOrder'])->where('shop', $shopSlug);
    // Quotation-first flow (platform slug variants).
    Route::post('/{shop}/quote',    [\App\Http\Controllers\Storefront\StorefrontController::class, 'requestQuote'])->where('shop', $shopSlug);
    Route::get('/{shop}/q/{token}',         [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'show'])->where('shop', $shopSlug)->where('token', $qToken);
    Route::get('/{shop}/q/{token}/data',    [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'data'])->where('shop', $shopSlug)->where('token', $qToken);
    Route::get('/{shop}/q/{token}/pdf',     [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'pdf'])->where('shop', $shopSlug)->where('token', $qToken);
    Route::post('/{shop}/q/{token}/share',  [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'share'])->where('shop', $shopSlug)->where('token', $qToken);
    Route::post('/{shop}/q/{token}/approve',[\App\Http\Controllers\Storefront\QuotationPortalController::class, 'approve'])->where('shop', $shopSlug)->where('token', $qToken);
    Route::post('/{shop}/q/{token}/revise', [\App\Http\Controllers\Storefront\QuotationPortalController::class, 'revise'])->where('shop', $shopSlug)->where('token', $qToken);
    Route::post('/{shop}/event',    [\App\Http\Controllers\Storefront\StorefrontController::class, 'recordEvent'])->where('shop', $shopSlug);
    Route::post('/{shop}/auth/request', [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'request'])->where('shop', $shopSlug);
    Route::post('/{shop}/auth/verify',  [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'verify'])->where('shop', $shopSlug);
    Route::post('/{shop}/account/orders',  [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'myOrders'])->where('shop', $shopSlug);
    Route::post('/{shop}/account/profile', [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'saveProfile'])->where('shop', $shopSlug);
});
require __DIR__.'/winworld.php';
