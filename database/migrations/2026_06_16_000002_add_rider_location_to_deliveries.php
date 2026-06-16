<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('deliveries', function (Blueprint $t) {
            $t->double('rider_lat')->nullable()->after('rider_token');
            $t->double('rider_lng')->nullable()->after('rider_lat');
            $t->timestamp('rider_loc_at')->nullable()->after('rider_lng'); // last GPS ping from the rider
        });
    }
    public function down(): void {
        Schema::table('deliveries', function (Blueprint $t) {
            $t->dropColumn(['rider_lat', 'rider_lng', 'rider_loc_at']);
        });
    }
};
