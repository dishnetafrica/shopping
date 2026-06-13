<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $t->string('phone')->nullable()->after('email');
            $t->string('role')->default('staff')->after('phone');
            $t->boolean('is_super_admin')->default(false)->after('role');
        });
        Schema::table('users', fn (Blueprint $t) => $t->string('email')->nullable()->change());
    }
    public function down(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tenant_id');
            $t->dropColumn(['phone','role','is_super_admin']);
        });
    }
};
