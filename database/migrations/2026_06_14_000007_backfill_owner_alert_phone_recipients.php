<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backward compatibility: every tenant's existing owner_alert_phone number(s)
 * become Order Notification Recipients, so no tenant loses new-order alerts
 * after deployment. Idempotent — re-running won't create duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('tenants')->get() as $t) {
            $settings = $t->settings ?? null;
            if (is_string($settings)) {
                $settings = json_decode($settings, true) ?: [];
            }
            $raw  = is_array($settings) ? (string) ($settings['owner_alert_phone'] ?? '') : '';
            $nums = preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($nums as $n) {
                $phone = preg_replace('/\D+/', '', $n);
                if ($phone === '') continue;

                $exists = DB::table('order_notification_recipients')
                    ->where('tenant_id', $t->id)->where('phone', $phone)->exists();
                if ($exists) continue;

                DB::table('order_notification_recipients')->insert([
                    'tenant_id'  => $t->id,
                    'name'       => 'Owner',
                    'phone'      => $phone,
                    'active'     => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only remove the auto-imported "Owner" rows; leave anything added in the UI.
        DB::table('order_notification_recipients')->where('name', 'Owner')->delete();
    }
};
