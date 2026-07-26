<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset Lifecycle · S4a — a cheap flag so the sale path and UI can tell "this product is a bundle"
 * without a join on every product. Maintained automatically by KitService (true once a kit has parts,
 * false when its last part is removed). Additive, defaults false — every existing product is unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (! Schema::hasColumn('products', 'is_kit')) {
                $t->boolean('is_kit')->default(false)->after('serialized');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            if (Schema::hasColumn('products', 'is_kit')) {
                $t->dropColumn('is_kit');
            }
        });
    }
};
