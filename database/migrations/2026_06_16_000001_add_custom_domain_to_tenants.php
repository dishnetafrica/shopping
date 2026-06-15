<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('tenants', function (Blueprint $t) {
            // A shop's own domain (e.g. "palssnack.com"). When a request arrives on
            // this host, the root storefront is served for that tenant. The slug URL
            // (mycloudbss.com/{slug}) always keeps working regardless.
            $t->string('custom_domain')->nullable()->unique()->after('slug');
        });
    }
    public function down(): void {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropUnique(['custom_domain']);
            $t->dropColumn('custom_domain');
        });
    }
};
