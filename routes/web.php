<?php

use App\Http\Controllers\Marketing\MarketingController;
use App\Http\Controllers\Panel\PanelApiController;
use App\Http\Controllers\Panel\PwaController;
use App\Http\Controllers\Panel\SellerPanelController;
use App\Http\Controllers\Panel\TrackController;
use App\Http\Middleware\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

// Public CloudBSS marketing landing page. Shop owners log in via /app/login,
// operator via /admin/login (both linked from the page).
Route::get('/', [MarketingController::class, 'home']);

// Public customer order-tracking page (no auth — order id + secret token are the key).
Route::get('/papi/track', [TrackController::class, 'show']);
Route::get('/r/{token}',     [\App\Http\Controllers\Panel\RiderTrackController::class, 'show']);
Route::post('/r/{token}/loc', [\App\Http\Controllers\Panel\RiderTrackController::class, 'loc']);

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
Route::middleware(['web', 'auth', SetTenantFromUser::class])->group(function () {
    Route::get('/panel', [SellerPanelController::class, 'show']);
    Route::match(['get', 'post'], '/panel/logout', [SellerPanelController::class, 'logout']);
    Route::get('/panel/m', [SellerPanelController::class, 'mobile']);
    Route::get('/panel/chats', [SellerPanelController::class, 'chats']);
    Route::get('/panel/cashbook', [SellerPanelController::class, 'cashbook']);
    Route::get('/panel/staff', [SellerPanelController::class, 'staff']);
    Route::get('/panel/scheduled', [SellerPanelController::class, 'scheduled']);
    Route::get('/panel/marketing', [SellerPanelController::class, 'marketing']);
    Route::get('/panel/diagnostics', [SellerPanelController::class, 'diagnostics']);
    Route::get('/panel/leads', [SellerPanelController::class, 'leads']);
    Route::get('/panel/setup', [SellerPanelController::class, 'setup']);
    Route::get('/panel/billing', [\App\Http\Controllers\Billing\BillingController::class, 'page']);

    // JSON API the panel calls. GET for everything (the panel uses query-string
    // writes); upload-image is POST. Tenant scoping is automatic.
    Route::prefix('papi')->group(function () {
        // auth (no-op; session already established)
        Route::get('auth-request',  [PanelApiController::class, 'authRequest']);
        Route::get('auth-verify',   [PanelApiController::class, 'authVerify']);

        // reads
        Route::get('orders',     [PanelApiController::class, 'orders']);
        Route::get('kitchen',         [PanelApiController::class, 'kitchen']);
        Route::get('kitchen-advance', [PanelApiController::class, 'kitchenAdvance']);
        Route::get('products',   [PanelApiController::class, 'products']);
        Route::get('riders',     [PanelApiController::class, 'riders']);
        Route::get('returns',    [PanelApiController::class, 'returns']);

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
        Route::get('update-product', [PanelApiController::class, 'updateProduct']);
        Route::get('delete-product', [PanelApiController::class, 'deleteProduct']);
        Route::get('add-product',    [PanelApiController::class, 'addProduct']);
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
$shopSlug = '(?!(app|admin|panel|papi|api|storage|livewire|build|vendor|up|login|logout|register)$)[a-z0-9][a-z0-9\-]*';

Route::middleware('web')->group(function () use ($shopSlug) {
    Route::get('/{shop}',           [\App\Http\Controllers\Storefront\StorefrontController::class, 'landing'])->where('shop', $shopSlug);
    Route::get('/{shop}/shop',      [\App\Http\Controllers\Storefront\StorefrontController::class, 'show'])->where('shop', $shopSlug);
    Route::get('/{shop}/catalogue', [\App\Http\Controllers\Storefront\StorefrontController::class, 'catalogue'])->where('shop', $shopSlug);
    Route::post('/{shop}/order',    [\App\Http\Controllers\Storefront\StorefrontController::class, 'placeOrder'])->where('shop', $shopSlug);
    Route::post('/{shop}/auth/request', [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'request'])->where('shop', $shopSlug);
    Route::post('/{shop}/auth/verify',  [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'verify'])->where('shop', $shopSlug);
    Route::post('/{shop}/account/orders',  [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'myOrders'])->where('shop', $shopSlug);
    Route::post('/{shop}/account/profile', [\App\Http\Controllers\Storefront\CustomerAuthController::class, 'saveProfile'])->where('shop', $shopSlug);
});
require __DIR__.'/winworld.php';
