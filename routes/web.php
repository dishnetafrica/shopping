<?php

use App\Http\Controllers\Panel\PanelApiController;
use App\Http\Controllers\Panel\SellerPanelController;
use App\Http\Middleware\SetTenantFromUser;
use Illuminate\Support\Facades\Route;

// Default landing -> the familiar seller panel.
Route::get('/', fn () => redirect('/panel'));

// The customer's existing Family Shopper seller UI, served verbatim behind the
// /app web session. (Filament business panel stays available at /app.)
Route::middleware(['web', 'auth', SetTenantFromUser::class])->group(function () {
    Route::get('/panel', [SellerPanelController::class, 'show']);

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

        // writes that persist
        Route::get('update-status',  [PanelApiController::class, 'updateStatus']);
        Route::get('save-order',     [PanelApiController::class, 'saveOrder']);
        Route::get('update-product', [PanelApiController::class, 'updateProduct']);
        Route::get('add-product',    [PanelApiController::class, 'addProduct']);
        Route::post('upload-image',  [PanelApiController::class, 'uploadImage']);

        // writes wired in Phase 3b (return ok:false -> panel saves locally for now)
        Route::match(['get', 'post'], 'dispatch',        [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'rider-save',      [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'rider-delete',    [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'return',          [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'settings-save',   [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'bot-config-save', [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'branch-save',     [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'branch-delete',   [PanelApiController::class, 'pending']);
        Route::match(['get', 'post'], 'customer-save',   [PanelApiController::class, 'pending']);
    });
});
