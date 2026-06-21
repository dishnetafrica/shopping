<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            // image_url stays the HERO image (unchanged). These add up to 3 gallery shots
            // (e.g. packaging, back label, lifestyle) sent on a "more photos" request.
            foreach (['gallery_1', 'gallery_2', 'gallery_3'] as $col) {
                if (! Schema::hasColumn('products', $col)) {
                    $t->string($col, 1024)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            foreach (['gallery_1', 'gallery_2', 'gallery_3'] as $col) {
                if (Schema::hasColumn('products', $col)) $t->dropColumn($col);
            }
        });
    }
};
