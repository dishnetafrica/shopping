<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_validations', function (Blueprint $t) {
            foreach (['products_accuracy', 'faq_accuracy', 'delivery_accuracy', 'offer_accuracy', 'language_accuracy'] as $col) {
                if (! Schema::hasColumn('field_validations', $col)) {
                    $t->integer($col)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('field_validations', function (Blueprint $t) {
            foreach (['products_accuracy', 'faq_accuracy', 'delivery_accuracy', 'offer_accuracy', 'language_accuracy'] as $col) {
                if (Schema::hasColumn('field_validations', $col)) $t->dropColumn($col);
            }
        });
    }
};
