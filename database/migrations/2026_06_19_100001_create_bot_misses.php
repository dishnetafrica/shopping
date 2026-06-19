<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Living log of terms the bot failed to match — the engine of continuous NLU improvement. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bot_misses')) return;
        Schema::create('bot_misses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('term', 120)->index();
            $t->unsignedInteger('count')->default(1);
            $t->string('sample', 200)->nullable();
            $t->boolean('resolved')->default(false);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'term']);
        });
    }
    public function down(): void { Schema::dropIfExists('bot_misses'); }
};
