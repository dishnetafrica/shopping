<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('type', 16);                                // search|zero_result|click|add
            $t->string('query', 191)->nullable();
            $t->integer('results')->default(0);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->timestamp('created_at')->nullable()->index();

            $t->index(['tenant_id', 'type', 'created_at']);
            $t->index(['tenant_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_events');
    }
};
