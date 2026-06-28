<?php
namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use App\Support\Vertical;
use Illuminate\Console\Command;

/**
 * Conversational evening nudge: once per day, asks an owner to set up tomorrow so the bot's
 * knowledge stays fresh. The reply flows back through the normal webhook → merchant lane.
 *
 * Vertical-aware: a restaurant is asked about the menu, a grocery/snacks shop about stock & new
 * items. Manufacturers have no daily menu, so they are NOT nudged by default (they were previously
 * receiving the restaurant "Breakfast/lunch/dinner" prompt, which is meaningless for them). A tenant
 * can always override with a custom `menu_nudge.template`, or disable with `menu_nudge.enabled=false`.
 */
class OkeNudgeCommand extends Command
{
    protected $signature = 'oke:nudge {--force : ignore time/once-per-day guards (testing)}';
    protected $description = 'Send the daily "prepare tomorrow" nudge to owners (vertical-aware)';

    private const TPL_RESTAURANT = "Good evening 👋\nLet's set up tomorrow.\n\n• Breakfast same as today?\n• Any lunch changes?\n• Any dinner / specials?\n• Anything sold out?\n• Any new items?\n• Any holiday tomorrow?\n\nJust reply in your own words.";

    private const TPL_GROCERY = "Good evening 👋\nQuick stock check for tomorrow.\n\n• Anything out of stock?\n• Any new items in?\n• Any price changes?\n• Any offers tomorrow?\n• Any holiday tomorrow?\n\nJust reply in your own words.";

    private const TPL_SNACKS = "Good evening 👋\nLet's set up tomorrow.\n\n• Same items available?\n• Anything sold out?\n• Any fresh batches or new items?\n• Any pre-orders to note?\n• Any holiday tomorrow?\n\nJust reply in your own words.";

    public function handle(): int
    {
        $today = date('Y-m-d');
        $sent = 0;

        Tenant::query()->whereNotNull('whatsapp_instance')->get()->each(function (Tenant $tenant) use ($today, &$sent) {
            try {
                $cfg = (array) $tenant->setting('menu_nudge', []);
                if (! ($cfg['enabled'] ?? true)) return;
                if (! $tenant->ownerAlertNumbers()) return;

                // Pick a template that fits the tenant's vertical. A custom template always wins;
                // otherwise manufacturers (and any vertical with no sensible daily prompt) are skipped.
                $template = $cfg['template'] ?? $this->defaultTemplate($tenant);
                if ($template === null || trim($template) === '') return;

                if (! $this->option('force')) {
                    if (($cfg['last_sent_date'] ?? null) === $today) return;
                    if (! $this->timeReached((string) ($cfg['time'] ?? '20:00'), (string) ($cfg['timezone'] ?? 'Africa/Kampala'))) return;
                }

                NotifyOwner::dispatch($tenant->id, $template);
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

    /** Default daily prompt for the tenant's vertical, or null if a daily nudge doesn't fit. */
    private function defaultTemplate(Tenant $tenant): ?string
    {
        switch (Vertical::of($tenant)) {
            case Vertical::RESTAURANT: return self::TPL_RESTAURANT;
            case Vertical::GROCERY:    return self::TPL_GROCERY;
            case Vertical::SNACKS:     return self::TPL_SNACKS;
            default:                   return null;   // manufacturer / unknown — no daily nudge
        }
    }

    private function timeReached(string $hhmm, string $tz): bool
    {
        try { $now = new \DateTime('now', new \DateTimeZone($tz)); }
        catch (\Throwable) { $now = new \DateTime('now'); }
        return $now->format('H:i') >= $hhmm;
    }
}
