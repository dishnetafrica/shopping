<?php
use App\Http\Controllers\Bot\WebhookController;
use Illuminate\Support\Facades\Route;

// One inbound webhook for every tenant. Point each Evolution/Cloud instance here.
//   Evolution:  https://app.<domain>/api/webhook/whatsapp/evolution
//   Cloud API:  https://app.<domain>/api/webhook/whatsapp/cloud
Route::match(['get','post'], '/webhook/whatsapp/{driver?}', [WebhookController::class, 'handle']);
