<?php
use App\Http\Controllers\Bot\WebhookController;
use Illuminate\Support\Facades\Route;

// One inbound webhook for every tenant. Point each Evolution/Cloud instance here.
//   Evolution:  https://app.<domain>/api/webhook/whatsapp/evolution
//   Cloud API:  https://app.<domain>/api/webhook/whatsapp/cloud
Route::match(['get','post'], '/webhook/whatsapp/{driver?}', [WebhookController::class, 'handle']);

// Smart-bot bridge: the shared n8n workflow posts its decisions back here. Auth = per-tenant
// shared secret (X-CloudBSS-Secret), enforced inside the controller; only works while bot_mode=n8n.
use App\Http\Controllers\Api\BotBridgeController;
Route::post('/bot/reply',            [BotBridgeController::class, 'reply']);
Route::post('/bot/alert',            [BotBridgeController::class, 'alert']);
Route::get('/tenant/{tenant}/catalog', [BotBridgeController::class, 'catalog']);

// Payment provider webhooks (called by Flutterwave / Stripe; verified inside).
use App\Http\Controllers\Billing\BillingController;
Route::post('/billing/flutterwave/webhook', [BillingController::class, 'flutterwaveWebhook']);
Route::post('/billing/stripe/webhook',      [BillingController::class, 'stripeWebhook']);

// ─── Inventory Trust v1 ───────────────────────────────────────────────────────
// Vision Gateway → CloudBSS event ingestion (shared-secret header X-Vision-Token).
// Fleet boxes use their per-box token from vision:claim; legacy fallback pair in .env:
//   VISION_WEBHOOK_TOKEN=<long random>   VISION_TENANT_ID=17     (config/vision.php)
Route::post('/vision/events', [\App\Http\Controllers\Api\VisionEventController::class, 'store']);

// Vision box zero-touch provisioning (handover §8): factory-identical boxes register here,
// show a claim code on screen, and poll until `php artisan vision:claim CODE --tenant=N`.
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/vision/box/hello', [\App\Http\Controllers\Api\VisionBoxController::class, 'hello']);
    Route::post('/vision/box/poll',  [\App\Http\Controllers\Api\VisionBoxController::class, 'poll']);
});
