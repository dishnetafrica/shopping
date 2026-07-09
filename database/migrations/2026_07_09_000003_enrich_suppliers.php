<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Full supplier records: contact id, business/contact names, email, tax, address, active. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) return;
        Schema::table('suppliers', function (Blueprint $t) {
            if (! Schema::hasColumn('suppliers','contact_id'))    $t->string('contact_id', 40)->nullable();
            if (! Schema::hasColumn('suppliers','business_name')) $t->string('business_name', 190)->nullable();
            if (! Schema::hasColumn('suppliers','contact_name'))  $t->string('contact_name', 190)->nullable();
            if (! Schema::hasColumn('suppliers','email'))         $t->string('email', 190)->nullable();
            if (! Schema::hasColumn('suppliers','tax_number'))    $t->string('tax_number', 60)->nullable();
            if (! Schema::hasColumn('suppliers','address'))       $t->text('address')->nullable();
            if (! Schema::hasColumn('suppliers','active'))        $t->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suppliers')) return;
        Schema::table('suppliers', function (Blueprint $t) {
            foreach (['contact_id','business_name','contact_name','email','tax_number','address','active'] as $c)
                if (Schema::hasColumn('suppliers',$c)) $t->dropColumn($c);
        });
    }
};
