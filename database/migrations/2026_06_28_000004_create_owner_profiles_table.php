<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lightweight per-owner communication profile (language, tz, style, learned phrase aliases). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('owner_profiles', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('owner_ref')->nullable();                 // phone / user id
            $t->string('language')->default('en');
            $t->string('timezone')->default('Africa/Kampala');
            $t->string('style')->default('terse');
            $t->jsonb('aliases_json')->nullable();               // phrase => canonical token
            $t->jsonb('learned_json')->nullable();               // reserved for Phase 3 auto-learning
            $t->timestamps();
            $t->unique(['tenant_id', 'owner_ref']);
        });
    }
    public function down(): void { Schema::dropIfExists('owner_profiles'); }
};
