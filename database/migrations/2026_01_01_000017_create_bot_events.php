<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_events')) {
            Schema::create('bot_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('trace', 64)->index();      // ties one message's steps together
                $table->string('phone', 32)->nullable();
                $table->string('stage', 24);               // queued|started|skipped|paused|replied|empty|error
                $table->string('detail', 300)->nullable();
                $table->integer('ms')->nullable();          // total latency on the final step
                $table->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_events');
    }
};
