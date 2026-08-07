<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.59 — the discovery log.
 *
 * Every run of every provider, with what it found and what happened to it. This exists so that a
 * provider which quietly returns nothing is VISIBLE rather than assumed to be working — the failure
 * mode of every integration against somebody else's API, and one that otherwise takes weeks to
 * notice because "no new businesses" looks exactly like "no new businesses in the area".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('discovery_runs')) {
            return;
        }
        Schema::create('discovery_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('provider', 40);                  // registry key: csv | places | …
            $t->string('status', 16)->default('ok');     // ok | empty | failed
            $t->json('query')->nullable();               // what was asked for, minus anything bulky
            $t->integer('found')->default(0);            // businesses the provider returned
            $t->integer('usable')->default(0);           // …that had enough to identify at all
            $t->integer('duplicates')->default(0);       // …that collided with another in the same run
            $t->text('note')->nullable();                // why it was empty, or what threw
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();

            $t->index(['tenant_id', 'provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
