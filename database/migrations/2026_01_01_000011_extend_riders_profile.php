<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $t) {
            // identity + payment details the panel collects (license, NID, bank, commission…)
            $t->json('profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('riders', function (Blueprint $t) {
            $t->dropColumn('profile');
        });
    }
};
