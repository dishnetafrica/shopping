<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->double('lat')->nullable();
            $t->double('lng')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('branches'); }
};
