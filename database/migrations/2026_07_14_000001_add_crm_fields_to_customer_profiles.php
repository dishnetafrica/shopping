<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive CRM fields for customer profiles (contact person, segment, city, country, credit limit).
 * All nullable — existing customers and every existing query keep working untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('customer_profiles', 'contact_person')) $t->string('contact_person')->nullable()->after('name');
            if (! Schema::hasColumn('customer_profiles', 'segment'))        $t->string('segment', 40)->nullable()->after('contact_person');
            if (! Schema::hasColumn('customer_profiles', 'city'))           $t->string('city', 80)->nullable()->after('address');
            if (! Schema::hasColumn('customer_profiles', 'country'))        $t->string('country', 40)->nullable()->after('city');
            if (! Schema::hasColumn('customer_profiles', 'credit_limit'))   $t->decimal('credit_limit', 14, 2)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profiles', function (Blueprint $t) {
            foreach (['contact_person', 'segment', 'city', 'country', 'credit_limit'] as $c) {
                if (Schema::hasColumn('customer_profiles', $c)) $t->dropColumn($c);
            }
        });
    }
};
