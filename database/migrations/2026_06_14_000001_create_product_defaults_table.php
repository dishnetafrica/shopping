<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('term', 64);                 // canonical, lowercased, synonym-mapped (e.g. 'rice')
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->string('source', 16)->default('owner');   // 'owner' | 'auto'
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'term']);       // one default per term per store
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_defaults');
    }
};
