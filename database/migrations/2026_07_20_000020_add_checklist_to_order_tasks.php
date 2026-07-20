<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair: the `checklist` column is defined in the create-migration for order_tasks,
 * but on databases created from an earlier revision of that migration it never landed
 * (create-migrations don't re-run). Its absence silently broke ALL order-task creation
 * (the engine sets checklist unconditionally). This idempotent migration adds it if
 * missing, restoring order-fulfilment task generation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_tasks')) return;
        if (Schema::hasColumn('order_tasks', 'checklist')) return;

        Schema::table('order_tasks', function (Blueprint $t) {
            $t->json('checklist')->nullable();
        });
    }

    public function down(): void
    {
        // Intentionally no-op: dropping checklist would re-break order tasks.
        // (The column is nullable and harmless to keep.)
    }
};
