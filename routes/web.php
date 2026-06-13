<?php

use App\Http\Controllers\Panel\PanelApiController;
use App\Http\Controllers\Panel\PwaController;
use App\Http\Controllers\Panel\SellerPanelController;
use App\Http\Controllers\Panel\TrackController;
use App\Http\Middleware\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

// Default landing -> the familiar seller panel.
Route::get('/', fn () => redirect('/panel'));

// Public customer order-tracking page (no auth — order id + secret token are the key).
Route::get('/papi/track', [TrackController::class, 'show']);

// PWA assets (public — a manifest / service worker / icon must load without a session).
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest']);
Route::get('/sw.js',                [PwaController::class, 'sw']);
Route::get('/apple-touch-icon.png', fn () => app(PwaController::class)->icon('apple-touch-icon.png'));
Route::get('/icons/{name}',         [PwaController::class, 'icon']);

// The customer's existing Family Shopper seller UI, served verbatim behind the
// /app web session. (Filament business panel stays available at /app.)
Route::middleware(['web', 'auth', SetTenantFromUser::class])->group(function () {
    Route::get('/panel', [SellerPanelController::class, 'show']);
    Route::get('/panel/chats', [SellerPanelController::class, 'chats']);
    Route::get('/panel/setup', [SellerPanelController::class, 'setup']);

    // JSON API the panel calls. GET for everything (the panel uses query-string
    // writes); upload-image is POST. Tenant scoping is automatic.
    Route::prefix('papi')->group(function () {
        // auth (no-op; session already established)
        Route::get('auth-request',  [PanelApiController::class, 'authRequest']);
        Route::get('auth-verify',   [PanelApiController::class, 'authVerify']);

        // reads
        Route::get('orders',     [PanelApiController::class, 'orders']);
        Route::get('products',   [PanelApiController::class, 'products']);
        Route::get('riders',     [PanelApiController::class, 'riders']);
        Route::get('returns',    [PanelApiController::class, 'returns']);
        Route::get('settings',   [PanelApiController::class, 'settings']);
        Route::get('branches',   [PanelApiController::class, 'branches']);
        Route::get('customers',  [PanelApiController::class, 'customers']);
        Route::get('bot-config', [PanelApiController::class, 'botConfig']);

        // live chats inbox (Phase 4b)
        Route::get('chats',              [PanelApiController::class, 'chats']);
        Route::get('chats/thread',       [PanelApiController::class, 'chatThread']);
        Route::post('chats/send',        [PanelApiController::class, 'chatSend']);
        Route::post('chats/takeover',    [PanelApiController::class, 'chatTakeover']);
        Route::post('chats/bot-mode',    [PanelApiController::class, 'chatBotMode']);
        Route::post('chats/sync',        [PanelApiController::class, 'chatSync']);
        Route::get('chats/sync-debug',    [PanelApiController::class, 'chatSyncDebug']);

        // self-serve onboarding (WhatsApp QR connect + AI bot setup)
        Route::get('wa/status',      [PanelApiController::class, 'waStatus']);
        Route::post('wa/connect',    [PanelApiController::class, 'waConnect']);
        Route::get('wa/qr',          [PanelApiController::class, 'waQr']);
        Route::post('wa/disconnect', [PanelApiController::class, 'waDisconnect']);
        Route::post('bot/generate',  [PanelApiController::class, 'botGenerate']);
        Route::post('bot/save',      [PanelApiController::class, 'botSave']);

        // writes that persist
        Route::get('update-status',  [PanelApiController::class, 'updateStatus']);
        Route::get('save-order',     [PanelApiController::class, 'saveOrder']);
        Route::get('update-product', [PanelApiController::class, 'updateProduct']);
        Route::get('add-product',    [PanelApiController::class, 'addProduct']);
        Route::post('upload-image',  [PanelApiController::class, 'uploadImage']);

        // writes wired in Phase 3b (return ok:false -> panel saves locally for now)
        Route::get('dispatch',     [PanelApiController::class, 'dispatch']);
        Route::get('rider-save',   [PanelApiController::class, 'riderSave']);
        Route::get('rider-delete', [PanelApiController::class, 'riderDel']);
        Route::get('return',          [PanelApiController::class, 'returnSave']);
        Route::get('settings-save',   [PanelApiController::class, 'settingsSave']);
        Route::get('bot-config-save', [PanelApiController::class, 'botConfigSave']);
        Route::get('branch-save',     [PanelApiController::class, 'branchSave']);
        Route::get('branch-delete',   [PanelApiController::class, 'branchDel']);
        Route::get('customer-save',   [PanelApiController::class, 'customerSave']);
    });
});
