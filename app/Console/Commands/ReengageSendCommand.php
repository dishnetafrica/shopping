<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Engagement\EngagementSender;
use Illuminate\Console\Command;

/**
 * reengage:send-tick — Phase 2b PACED sender for the daily re-engagement list.
 *
 * Runs on a short interval and sends only a small batch per tenant per tick, daytime only. The slow
 * pace is the safety feature: on an unofficial (Evolution) number, fast marketing is how numbers get
 * banned. Triple-gated and OFF by default:
 *   1. engagement_enabled       — the daily plan is being built at all
 *   2. engagement_send_enabled  — the owner has explicitly turned sending on (default OFF)
 *   3. daytime window           — 08:00–20:00 in the tenant's timezone (skip with --force for testing)
 * The per-candidate consent/frequency/cap/quality rules live in EngagementSender.
 */
class ReengageSendCommand extends Command
{
    protected $signature = 'reengage:send-tick {--tenant=0} {--force : ignore the daytime window (testing)}';

    protected $description = 'Phase 2b: send a small paced batch from today\'s re-engagement list (opted-in only, capped)';

    public function handle(EngagementSender $sender): int
    {
        $only = (int) $this->option('tenant');

        $tenants = Tenant::query()
            ->when($only > 0, fn ($q) => $q->whereKey($only))
            ->get();

        foreach ($tenants as $t) {
            app(\App\Support\TenantContext::class)->set((int) $t->id);

            if (! (bool) $t->setting('engagement_enabled', false)) {
                continue;
            }
            if (! (bool) $t->setting('engagement_send_enabled', false)) {
                continue;                              // sending is a separate, explicit switch
            }

            // Daytime window in the tenant's timezone — never marketing at 02:00.
            $tz   = (string) ($t->setting('timezone', '') ?: 'Africa/Kampala');
            $hour = (int) now($tz)->format('G');
            if (! $this->option('force') && ($hour < 8 || $hour >= 20)) {
                continue;
            }

            $batch = max(1, (int) $t->setting('engagement_send_batch', EngagementSender::BATCH_DEFAULT));
            $r = $sender->sendTick($t, $batch);

            if ($only > 0 || $r['sent'] > 0 || $r['paused']) {
                $line = "reengage:send-tick · tenant {$t->id} ({$t->name}): sent {$r['sent']}";
                if ($r['blocked']) { $line .= " · blocked {$r['blocked']}"; }
                if ($r['skipped']) { $line .= " · freq-skip {$r['skipped']}"; }
                if ($r['failed'])  { $line .= " · failed {$r['failed']}"; }
                if ($r['capped'])  { $line .= ' · daily cap reached'; }
                if ($r['paused'])  { $line .= ' · PAUSED (quality)'; }
                $this->info($line);
            }
        }

        return self::SUCCESS;
    }
}
