<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Advisor — a captured project (discovery brief + computed sizing).
 *
 * One row per advisory engagement: the salesperson's questionnaire answers (jsonb), the deterministic
 * sizing snapshot the engine produced (jsonb), the profile/industry, an optional customer link and
 * author, and a status. Generic across industries (profile column), tenant-scoped, additive.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('advisor_projects')) {
            return;
        }
        Schema::create('advisor_projects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('customer_id')->nullable()->index();  // optional link, no hard FK
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('profile', 32)->default('solar');                 // industry engine key
            $t->string('name')->nullable();                              // e.g. "Nakawa home backup"
            $t->string('status', 16)->default('draft');                  // draft | sized | proposed | won | lost
            $t->jsonb('answers')->nullable();                            // discovery questionnaire answers
            $t->jsonb('sizing')->nullable();                             // deterministic engine result snapshot
            $t->timestamps();

            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'profile']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_projects');
    }
};
