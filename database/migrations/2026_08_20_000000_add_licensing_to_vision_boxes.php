<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the EXISTING vision_boxes registry with licensing fields (device keypair + signed-license
 * window + authorised versions). Reuses box_uid / claim_code / webhook_token / status / tenant_id —
 * this is an extension, not a second registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vision_boxes', function (Blueprint $t) {
            $t->text('device_pubkey')->nullable();                 // box-generated Ed25519 public key (b64)
            $t->text('fingerprint_hash')->nullable();              // JSON list of salted HW signal hashes
            $t->string('license_type', 24)->default('development'); // development|sample|trial|production
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedInteger('grace_seconds')->default(1209600); // 14d default
            $t->string('runtime_version_authorized', 40)->nullable();
            $t->string('model_version_authorized', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vision_boxes', function (Blueprint $t) {
            $t->dropColumn([
                'device_pubkey', 'fingerprint_hash', 'license_type', 'issued_at', 'expires_at',
                'revoked_at', 'grace_seconds', 'runtime_version_authorized', 'model_version_authorized',
            ]);
        });
    }
};
