<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'product_type')) {
                $t->string('product_type')->nullable()->index()->after('category');
            }
            if (! Schema::hasColumn('products', 'product_type_confidence')) {
                $t->float('product_type_confidence')->nullable()->after('product_type');
            }
            if (! Schema::hasColumn('products', 'product_type_status')) {
                // null = never enriched, 'approved' = auto-applied/confirmed, 'needs_review' = queued
                $t->string('product_type_status', 20)->nullable()->index()->after('product_type_confidence');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['product_type', 'product_type_confidence', 'product_type_status'] as $c) {
                if (Schema::hasColumn('products', $c)) $t->dropColumn($c);
            }
        });
    }
};
