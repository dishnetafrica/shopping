<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_memories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('category', 24);          // products|faqs|offers|delivery
            $t->string('fact');
            $t->integer('agreement')->default(0);
            $t->integer('confidence')->default(0);
            $t->json('employees')->nullable();
            $t->boolean('contested')->default(false);
            $t->timestamps();

            $t->index(['tenant_id', 'category']);
        });

        Schema::create('employee_memories', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('employee');
            $t->string('category', 24);          // style|upsell|unique_products|unique_faqs|unique_offers
            $t->string('fact')->nullable();
            $t->json('detail')->nullable();
            $t->integer('confidence')->default(0);
            $t->timestamps();

            $t->index(['tenant_id', 'employee']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_memories');
        Schema::dropIfExists('company_memories');
    }
};
