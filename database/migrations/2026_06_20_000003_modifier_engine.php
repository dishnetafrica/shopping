<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modifier-group engine (restaurant customisation).
 *
 *   modifier_groups        a reusable choice set, e.g. "Choice of accompaniment"
 *                          required=true, min=1, max=1, free_qty=1  (1 included free)
 *                          or "Extras" required=false, min=0, max=99, free_qty=0
 *   modifier_options       the choices inside a group: Rice (+0), Naan (+0), Butter Naan (+500)
 *   product_modifier_group attach groups to dishes (one group reused across many curries)
 *   order_items.modifiers  JSON snapshot of what the customer actually chose per line
 *
 * Pricing rule: a line's unit price = product price + sum(chosen option price_delta).
 * free_qty lets the first N picks in a group be free (the "1 included naan/rice").
 * Tenant-scoped; grocery tenants simply never create any groups.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modifier_groups')) {
            Schema::create('modifier_groups', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->string('name');                       // "Choice of accompaniment"
                $t->boolean('required')->default(false);  // must the customer pick?
                $t->unsignedInteger('min_select')->default(0);
                $t->unsignedInteger('max_select')->default(1);
                $t->unsignedInteger('free_qty')->default(0); // first N picks are free
                $t->unsignedInteger('sort')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('modifier_options')) {
            Schema::create('modifier_options', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('modifier_group_id')->index();
                $t->string('name');                       // "Naan", "Jeera Rice", "Butter Naan"
                $t->decimal('price_delta', 10, 2)->default(0);   // minor units, added to the line
                $t->unsignedInteger('sort')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('product_modifier_group')) {
            Schema::create('product_modifier_group', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('product_id')->index();
                $t->unsignedBigInteger('modifier_group_id')->index();
                $t->unsignedInteger('sort')->default(0);
                $t->unique(['product_id', 'modifier_group_id']);
            });
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'modifiers')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->json('modifiers')->nullable();        // [{group, name, price_delta}]
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'modifiers')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->dropColumn('modifiers');
            });
        }
        Schema::dropIfExists('product_modifier_group');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
    }
};
