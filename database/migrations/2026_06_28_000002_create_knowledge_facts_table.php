<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Business Memory: durable, append-only VERSIONED truths. Never overwrite — supersede. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('knowledge_facts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->index();
            $t->string('capability')->index();                   // owning capability
            $t->string('fact_type')->index();                    // Price|Schedule|Facility|Policy|...
            $t->string('key');                                   // e.g. "tea:price", "delivery:free_threshold"
            $t->jsonb('value_json')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_current')->default(true);
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->string('source')->default('owner_whatsapp');
            $t->decimal('confidence', 4, 3)->default(1.000);
            $t->string('scope')->default('durable');             // durable|dated
            $t->date('effective_from')->nullable();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'capability', 'fact_type', 'key']);
            $t->index(['tenant_id', 'key', 'is_current']);
        });
    }
    public function down(): void { Schema::dropIfExists('knowledge_facts'); }
};
