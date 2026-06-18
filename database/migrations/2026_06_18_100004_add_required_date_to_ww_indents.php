<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Win World: delivery required date (for delay-risk alerts) + alert dedupe stamp. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ww_indents')) return;
        Schema::table('ww_indents', function (Blueprint $t) {
            if (! Schema::hasColumn('ww_indents', 'required_date'))     $t->date('required_date')->nullable()->after('date_of_indent');
            if (! Schema::hasColumn('ww_indents', 'delay_alerted_at'))  $t->dateTime('delay_alerted_at')->nullable()->after('delay_days');
        });
    }
    public function down(): void
    {
        if (! Schema::hasTable('ww_indents')) return;
        Schema::table('ww_indents', function (Blueprint $t) {
            foreach (['required_date','delay_alerted_at'] as $c) if (Schema::hasColumn('ww_indents', $c)) $t->dropColumn($c);
        });
    }
};
