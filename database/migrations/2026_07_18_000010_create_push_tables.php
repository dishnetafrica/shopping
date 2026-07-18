<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Web Push (dependency-free): device subscriptions + the one VAPID keypair. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('endpoint', 500)->unique();
                $t->string('p256dh', 255);
                $t->string('auth', 255);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('push_keys')) {
            Schema::create('push_keys', function (Blueprint $t) {
                $t->id();
                $t->string('public_key', 255);
                $t->text('private_pem');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('push_keys');
    }
};
