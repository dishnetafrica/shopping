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
