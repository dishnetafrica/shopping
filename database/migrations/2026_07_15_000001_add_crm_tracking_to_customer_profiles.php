<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM tracking fields for the Customer 360: who owns the account, an open follow-up note, and the
 * last time the shop contacted them (call/visit) and over WhatsApp. All nullable and hasColumn-guarded
 * so re-running or partial schemas are safe. No engine tables touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_profiles', 'assigned_rep')) {
                $t->string('assigned_rep')->nullable();
            }
            if (! Schema::hasColumn('customer_profiles', 'follow_up')) {
                $t->string('follow_up')->nullable();
            }
            if (! Schema::hasColumn('customer_profiles', 'last_contacted_at')) {
                $t->timestamp('last_contacted_at')->nullable();
            }
            if (! Schema::hasColumn('customer_profiles', 'last_whatsapp_at')) {
                $t->timestamp('last_whatsapp_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $t) {
            foreach (['assigned_rep', 'follow_up', 'last_contacted_at', 'last_whatsapp_at'] as $c) {
                if (Schema::hasColumn('customer_profiles', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
