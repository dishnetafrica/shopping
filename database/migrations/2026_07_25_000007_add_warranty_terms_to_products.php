<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · S3b — a product's DEFAULT warranty policy (manufacturer + company months). These
 * are the terms a new warranty inherits when a unit of this product is sold/installed (auto-activation
 * lands in S3c). Additive, nullable — products without a policy are unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'warranty_manufacturer_months')) {
                $t->unsignedInteger('warranty_manufacturer_months')->nullable()->after('serialized');
            }
            if (! Schema::hasColumn('products', 'warranty_company_months')) {
                $t->unsignedInteger('warranty_company_months')->nullable()->after('warranty_manufacturer_months');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['warranty_manufacturer_months', 'warranty_company_months'] as $c) {
                if (Schema::hasColumn('products', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
