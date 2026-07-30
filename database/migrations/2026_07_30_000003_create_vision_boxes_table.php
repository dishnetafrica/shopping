<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vision Gateway fleet — box identity + claim-code provisioning (handover §8 / backlog V4).
 *
 * The one-click story: the factory flashes ONE identical image onto every box. A box knows
 * nothing about any store. At first boot in the store it registers here (hello), shows a short
 * claim code on its screen, and polls. `php artisan vision:claim CODE --tenant=17 --store=kampala`
 * binds it; the next poll hands the box its store id, webhook URL and its own per-box webhook
 * token. ADDITIVE ONLY — nothing existing is touched; env-token ingestion keeps working.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('vision_boxes')) {
            Schema::create('vision_boxes', function (Blueprint $t) {
                $t->id();
                $t->string('box_uid', 64)->unique();          // /etc/machine-id of the box
                $t->char('secret_sha256', 64);                // sha256 of the box-held secret (never stored plain)
                $t->string('claim_code', 12)->nullable()->index();
                $t->timestamp('claim_expires_at')->nullable();
                $t->string('status', 20)->default('pending'); // pending | claimed | revoked
                $t->foreignId('tenant_id')->nullable()->index();
                $t->string('store', 80)->nullable();          // store/location label pushed into the box .env
                $t->string('webhook_token', 96)->nullable()->unique(); // per-box X-Vision-Token
                $t->string('fw_version', 40)->nullable();
                $t->string('hw_model', 80)->nullable();
                $t->string('last_ip', 45)->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('claimed_at')->nullable();
                $t->json('meta')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_boxes');
    }
};
