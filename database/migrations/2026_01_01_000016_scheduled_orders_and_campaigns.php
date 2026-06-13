<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- scheduled delivery fields on orders ---
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'scheduled_for')) {
                    $table->timestamp('scheduled_for')->nullable()->index()->after('delivered_at');
                }
                if (! Schema::hasColumn('orders', 'sched_stage')) {
                    // Scheduled | Preparing | Ready For Dispatch | Out For Delivery | Delivered
                    $table->string('sched_stage', 32)->nullable()->after('scheduled_for');
                }
                if (! Schema::hasColumn('orders', 'sched_reminders')) {
                    $table->json('sched_reminders')->nullable()->after('sched_stage');
                }
            });
        }

        // --- marketing campaigns ---
        if (! Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('name')->nullable();
                $table->string('type', 32)->default('promotion');   // promotion | launch | discount | seasonal
                $table->string('audience', 32)->default('all');     // all | recent | inactive | vip | category
                $table->string('category')->nullable();             // when audience = category
                $table->text('message')->nullable();
                $table->string('image_url')->nullable();
                $table->json('product_ids')->nullable();
                $table->string('cta')->default('Reply BUY to order instantly.');
                $table->string('status', 16)->default('draft');     // draft | scheduled | sending | sent | failed
                $table->timestamp('scheduled_for')->nullable()->index();
                $table->json('stats')->nullable();                  // {targeted, sent, failed, started_at, finished_at}
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['scheduled_for', 'sched_stage', 'sched_reminders'] as $c) {
                    if (Schema::hasColumn('orders', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
