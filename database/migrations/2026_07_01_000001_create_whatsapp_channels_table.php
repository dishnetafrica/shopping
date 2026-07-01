<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('whatsapp_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->index();
            $t->string('instance')->unique();          // Evolution instance name OR Cloud API phone-number-id
            $t->string('number')->nullable();          // display number
            $t->string('driver')->default('evolution');
            $t->string('label')->nullable();           // e.g. "Sales", "Support", "Branch 2"
            $t->boolean('is_primary')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // Backfill: every tenant that already has a connected number becomes its PRIMARY channel,
        // so all existing single-number shops keep working with no change in behaviour.
        foreach (DB::table('tenants')->whereNotNull('whatsapp_instance')->get() as $row) {
            if (trim((string) $row->whatsapp_instance) === '') continue;
            $exists = DB::table('whatsapp_channels')->where('instance', $row->whatsapp_instance)->exists();
            if ($exists) continue;
            DB::table('whatsapp_channels')->insert([
                'tenant_id'  => $row->tenant_id ?? $row->id,
                'instance'   => $row->whatsapp_instance,
                'number'     => $row->whatsapp_number ?? null,
                'driver'     => $row->whatsapp_driver ?? 'evolution',
                'label'      => 'Primary',
                'is_primary' => true,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_channels');
    }
};
