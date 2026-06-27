<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Short-lived operational toggles (today/dated). Kept SEPARATE from durable knowledge_facts. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('operational_state', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('capability')->index();
            $t->string('key');
            $t->jsonb('value_json')->nullable();
            $t->string('scope')->default('today');               // today|dated
            $t->date('effective_date')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'capability', 'key', 'effective_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('operational_state'); }
};
