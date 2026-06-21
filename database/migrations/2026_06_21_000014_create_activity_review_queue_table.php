<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_review_queue', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('feed_item_id')->index();
            $t->string('status', 12)->default('pending');          // pending|approved|rejected|edited
            $t->string('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('created_at')->nullable();

            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_review_queue');
    }
};
