<?php

namespace App\Console\Commands;

use App\Models\VisionBox;
use Illuminate\Console\Command;

/**
 * Fleet view for Vision Gateway boxes — the artisan precursor of the V4 heartbeat page.
 *
 *   php artisan vision:boxes                    ← whole fleet, status + last-seen
 *   php artisan vision:boxes --tenant=17        ← one tenant
 *   php artisan vision:boxes --revoke=<uid>     ← kill a lost/stolen box (its token stops working)
 *   php artisan vision:boxes --forget=<uid>     ← delete a row so a reflashed box can re-hello fresh
 */
final class VisionBoxesCommand extends Command
{
    protected $signature = 'vision:boxes
        {--tenant= : filter by tenant id}
        {--revoke= : box_uid to revoke (blocks its webhook token + polls)}
        {--forget= : box_uid to delete entirely (re-registration allowed)}';

    protected $description = 'List / revoke / forget Vision Gateway boxes.';

    public function handle(): int
    {
        if ($uid = (string) $this->option('revoke')) {
            $box = VisionBox::where('box_uid', $uid)->first();
            if (! $box) { $this->error("No box {$uid}."); return self::FAILURE; }
            $box->forceFill(['status' => 'revoked', 'webhook_token' => null])->save();
            $this->info("Box {$uid} revoked — events and polls now rejected.");
            return self::SUCCESS;
        }

        if ($uid = (string) $this->option('forget')) {
            $n = VisionBox::where('box_uid', $uid)->delete();
            $this->info($n ? "Box {$uid} forgotten — it may hello again as new." : "No box {$uid}.");
            return $n ? self::SUCCESS : self::FAILURE;
        }

        $q = VisionBox::orderByDesc('last_seen_at');
        if ($t = (int) $this->option('tenant')) {
            $q->where('tenant_id', $t);
        }
        $boxes = $q->get();
        if ($boxes->isEmpty()) {
            $this->line('No boxes yet. A freshly flashed box appears here on its first hello.');
            return self::SUCCESS;
        }

        $rows = $boxes->map(fn (VisionBox $b) => [
            $b->box_uid,
            $b->status . ($b->status === 'pending' && $b->claim_code ? " [{$b->claim_code}]" : ''),
            $b->tenant_id ?: '—',
            $b->store ?: '—',
            $b->fw_version ?: '—',
            optional($b->last_seen_at)->diffForHumans() ?? 'never',
            $b->last_ip ?: '—',
        ])->all();

        $this->table(['box_uid', 'status', 'tenant', 'store', 'fw', 'last seen', 'ip'], $rows);
        return self::SUCCESS;
    }
}
