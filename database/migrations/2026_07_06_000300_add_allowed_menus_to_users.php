<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user menu override. NULL = use the role's default (effectiveMenus).
 * Array = this exact set of menu keys, overriding the role. Empty array = only dashboard.
 * Mirrors the existing allowed_categories pattern.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users','allowed_menus')) {
                $t->jsonb('allowed_menus')->nullable()->after('allowed_categories');
            }
        });
    }
    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users','allowed_menus')) $t->dropColumn('allowed_menus');
        });
    }
};
