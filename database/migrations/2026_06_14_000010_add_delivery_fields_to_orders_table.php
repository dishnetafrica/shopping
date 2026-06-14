<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('delivery_fee')->default(0)->after('total');
            $table->foreignId('delivery_zone_id')->nullable()->after('delivery_fee')->constrained('delivery_zones')->nullOnDelete();
            $table->timestamp('eta_at')->nullable()->after('delivery_zone_id');
        });
    }
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_zone_id');
            $table->dropColumn(['delivery_fee', 'eta_at']);
        });
    }
};
