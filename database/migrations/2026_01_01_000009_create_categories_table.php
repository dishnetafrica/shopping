<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->integer('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'name']);
        });
    }
    public function down(): void { Schema::dropIfExists('categories'); }
};
