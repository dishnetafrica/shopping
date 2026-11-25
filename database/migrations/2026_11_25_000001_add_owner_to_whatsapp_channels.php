<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WhatsApp number can belong to a salesperson (DishNet, 25 Nov 2026: "Aida has a separate number and we
 * want her to work the same as Justus"). A customer who first writes to that number becomes that
 * salesperson's customer (SalesOwnership::ownerForLine). Nullable: every existing number belongs to the
 * business as before, and other businesses are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $t) {
            if (! Schema::hasColumn('whatsapp_channels', 'owner_user_id')) {
                $t->unsignedBigInteger('owner_user_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $t) {
            if (Schema::hasColumn('whatsapp_channels', 'owner_user_id')) {
                $t->dropColumn('owner_user_id');
            }
        });
    }
};
