<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Conversational evening nudge: once per day, asks every tenant with an owner number to set up
 * tomorrow. The reply flows back through the normal webhook → merchant lane (+ OKE observe mode).
 * Scheduled every 15 min; each tenant fires only when its local nudge time has passed and it
 * hasn't been sent today.
 */
class OkeNudgeCommand extends Command
{
    protected $signature = 'oke:nudge {--force : ignore time/once-per-day guards (testing)}';
    protected $description = 'Send the daily "prepare tomorrow" nudge to owners';

    private const DEFAULT_TEMPLATE = "Good evening 👋\nLet's set up tomorrow.\n\n• Breakfast same as today?\n• Any lunch changes?\n• Any dinner / specials?\n• Anything sold out?\n• Any new items?\n• Any holiday tomorrow?\n\nJust reply in your own words.";

    public function handle(): int
    {
        $today = date('Y-m-d');
        $sent = 0;

        Tenant::query()->whereNotNull('whatsapp_instance')->get()->each(function (Tenant $tenant) use ($today, &$sent) {
            try {
                $cfg = (array) $tenant->setting('menu_nudge', []);
                if (! ($cfg['enabled'] ?? true)) return;
                if (! $tenant->ownerAlertNumbers()) return;
                if (! $this->option('force')) {
                    if (($cfg['last_sent_date'] ?? null) === $today) return;
                    if (! $this->timeReached((string) ($cfg['time'] ?? '20:00'), (string) ($cfg['timezone'] ?? 'Africa/Kampala'))) return;
                }

                NotifyOwner::dispatch($tenant->id, $cfg['template'] ?? self::DEFAULT_TEMPLATE);
                $cfg['last_sent_date'] = $today;
                $tenant->putSetting('menu_nudge', $cfg);
                $sent++;
            } catch (\Throwable $e) {
                $this->warn("nudge skipped for tenant {$tenant->id}: {$e->getMessage()}");
            }
        });

        $this->info("oke:nudge sent {$sent} nudge(s).");
        return self::SUCCESS;
    }

    private function timeReached(string $hhmm, string $tz): bool
    {
        try { $now = new \DateTime('now', new \DateTimeZone($tz)); }
        catch (\Throwable) { $now = new \DateTime('now'); }
        return $now->format('H:i') >= $hhmm;
    }
}
