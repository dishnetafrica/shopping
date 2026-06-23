<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale selling units. All nullable / opt-in — a product with none set behaves exactly as before,
 * so grocery/restaurant catalogues are unchanged. Used by wholesale tenants (e.g. EuroPearl Africa):
 *   unit_label = "carton", pack_size = 100 (rolls per carton), moq = 1 (minimum cartons per order).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedInteger('moq')->nullable();         // minimum order quantity (in selling units)
            $t->unsignedInteger('pack_size')->nullable();   // pieces per selling unit (for per-piece price)
            $t->string('unit_label')->nullable();           // e.g. carton / box / pack / roll
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn(['moq', 'pack_size', 'unit_label']);
        });
    }
};
