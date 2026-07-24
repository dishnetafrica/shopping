<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short, login-free "My Jobs" link token per user (the DishNet scheduling-link model).
 *   job_token      — a short random code used in https://.../jobs/{token}
 *   job_token_day  — the Juba calendar day (Ymd) it was minted, so it rotates daily
 * Additive, nullable, guarded. No behaviour change until task reminders send a link.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'job_token')) {
                $t->string('job_token', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'job_token_day')) {
                $t->string('job_token_day', 8)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'job_token')) {
                try { $t->dropIndex(['job_token']); } catch (\Throwable $e) {}
                $t->dropColumn('job_token');
            }
            if (Schema::hasColumn('users', 'job_token_day')) {
                $t->dropColumn('job_token_day');
            }
        });
    }
};
