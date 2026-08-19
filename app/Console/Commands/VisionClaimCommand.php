<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\VisionBox;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Claim a Vision Gateway box into a tenant (handover §8 one-click flow, CloudBSS side).
 *
 * The box shows a 6-character code on its screen at the store; you run:
 *
 *   php artisan vision:claim ABC234 --tenant=17 --store=kampala
 *
 * On its next poll (≤10 s) the box receives its store label, the webhook URL, and its own
 * per-box X-Vision-Token, writes them into /opt/cloudbss-vision/.env and restarts the
 * gateway. Nothing is ever typed on the box itself.
 */
final class VisionClaimCommand extends Command
{
    protected $signature = 'vision:claim
        {code : the claim code shown on the box screen}
        {--tenant= : tenant id to bind the box to (required)}
        {--store=kampala : store/location label the gateway stamps on every event}
        {--type=development : license type (development|sample|trial|production) — dev is the safe default for a supplier box}
        {--model= : authorised model version}
        {--runtime= : authorised runtime version}
        {--grace=1209600 : offline grace seconds (default 14d)}';

    protected $description = 'Bind a pending Vision Gateway box (by its on-screen claim code) to a tenant.';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant {$tenantId} not found.");
            return self::FAILURE;
        }

        $code = Str::upper(trim((string) $this->argument('code')));
        $box  = VisionBox::where('claim_code', $code)
            ->where('status', 'pending')
            ->where('claim_expires_at', '>', now())
            ->first();

        if (! $box) {
            $this->error("No pending box with live code {$code}. On the box screen the code refreshes after expiry — read the current one, or check `php artisan vision:boxes`.");
            return self::FAILURE;
        }

        $box->forceFill([
            'status'                     => 'claimed',
            'tenant_id'                  => $tenantId,
            'store'                      => (string) $this->option('store'),
            'webhook_token'              => Str::random(64),
            'claim_code'                 => null,
            'claim_expires_at'           => null,
            'claimed_at'                 => now(),
            // Licensing (fields added by the vision_boxes licensing migration). The signed license
            // itself is issued to the box on its next poll (VisionBoxController::claimedResponse).
            'license_type'               => (string) $this->option('type'),
            'model_version_authorized'   => $this->option('model'),
            'runtime_version_authorized' => $this->option('runtime'),
            'grace_seconds'              => (int) $this->option('grace'),
            'issued_at'                  => now(),
        ])->save();

        $this->info("Box {$box->box_uid} claimed → tenant {$tenantId} ({$tenant->name}), store '{$box->store}'.");
        $this->line('It will pick up its config on the next poll (≤10 s) and restart the gateway itself.');
        $this->line('Watch arrivals: vision_events rows with payload._box_uid = ' . $box->box_uid);

        return self::SUCCESS;
    }
}
