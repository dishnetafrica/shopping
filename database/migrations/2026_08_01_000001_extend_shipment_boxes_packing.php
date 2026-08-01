<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v4.7 bus-packing pack — a box now records HOW it is packed and WHOSE goods are inside,
 * replacing the WhatsApp-group improvisation ("1 box for gurpreet / 2box - rawu,michel /
 * packed in plastic"). All columns nullable + hasColumn-guarded: existing boxes unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipment_boxes')) {
            return;
        }
        Schema::table('shipment_boxes', function (Blueprint $t) {
            if (! Schema::hasColumn('shipment_boxes', 'packing')) {
                $t->string('packing', 40)->nullable();       // carton | plastic bag | sack | bale | drum | other
            }
            if (! Schema::hasColumn('shipment_boxes', 'note')) {
                $t->text('note')->nullable();                // free text: "postal sticks wrapped inside"
            }
            if (! Schema::hasColumn('shipment_boxes', 'order_ids')) {
                $t->json('order_ids')->nullable();           // which customer orders ride in this box
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipment_boxes')) {
            return;
        }
        Schema::table('shipment_boxes', function (Blueprint $t) {
            foreach (['packing', 'note', 'order_ids'] as $c) {
                if (Schema::hasColumn('shipment_boxes', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
