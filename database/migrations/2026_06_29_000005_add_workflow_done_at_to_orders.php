<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'workflow_done_at')) {
                $t->timestamp('workflow_done_at')->nullable();
            }
        });
    }
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (Schema::hasColumn('orders', 'workflow_done_at')) $t->dropColumn('workflow_done_at');
        });
    }
};
