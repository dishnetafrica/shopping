<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Unanswered-customer watchdog for n8n smart-bot tenants. A conversation with unread > 0 has had
 * no bot/agent/owner reply since the customer's last message; if it's been waiting longer than the
 * tenant's threshold (and we're inside business hours), alert the dispatch/sales team — once per
 * waiting message. Shared + tenant-keyed: one command serves every n8n tenant.
 */
class BotWatchdogCommand extends Command
{
    protected $signature = 'bot:watchdog';
    protected $description = 'Alert staff about customers the smart bot has not answered yet.';

    public function handle(WhatsAppManager $wa): int
    {
        $now = now();
        foreach (Tenant::all() as $t) {
            if ((string) $t->setting('bot_mode', '') !== 'n8n') continue;
            if (! $t->setting('watchdog_enabled', true)) continue;

            // Business hours in the tenant's local time (default EAT, UTC+3).
            $offset = (int) $t->setting('tz_offset', 3);
            $hour   = ((int) $now->copy()->utc()->format('G') + $offset + 24) % 24;
            [$h0, $h1] = $this->hours((string) $t->setting('watchdog_hours', '7-21'));
            if ($hour < $h0 || $hour >= $h1) continue;

            $waitMin = max(2, (int) $t->setting('watchdog_wait_min', 10));
            $maxMin  = max($waitMin, (int) $t->setting('watchdog_max_age_min', 180));

            $convos = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $t->id)
                ->where('unread', '>', 0)
                ->where('agent_active', false)
                ->whereNotNull('last_inbound_at')
                ->where('last_inbound_at', '<=', $now->copy()->subMinutes($waitMin))
                ->where('last_inbound_at', '>=', $now->copy()->subMinutes($maxMin))
                ->get();
            if ($convos->isEmpty()) continue;

            $to = $this->route($t, ['dispatch', 'sales', 'management']);
            if (! $to) continue;

            $lines = [];
            foreach ($convos as $c) {
                // one alert per waiting message (keyed by the inbound timestamp)
                $key = "wd:{$c->id}:" . optional($c->last_inbound_at)->timestamp;
                if (! Cache::add("bot_alert_once:{$t->id}:{$key}", 1, $waitMin * 60)) continue;
                $waited  = $c->last_inbound_at->diffInMinutes($now);
                $lines[] = "• {$c->customer_phone} — waiting {$waited}m";
            }
            if (! $lines) continue;

            $msg = "⏰ Unanswered customers (" . count($lines) . ")\n" . implode("\n", $lines);
            $this->send($wa, $t, $to, $msg, 'watchdog');
            $this->info("watchdog {$t->slug}: alerted " . count($lines));
        }
        return self::SUCCESS;
    }

    private function hours(string $spec): array
    {
        if (preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $spec, $m)) {
            return [max(0, min(23, (int) $m[1])), max(1, min(24, (int) $m[2]))];
        }
        return [7, 21];
    }

    private function route(Tenant $t, array $rolePriority): array
    {
        $routing = (array) $t->setting('alert_routing', []);
        foreach ($rolePriority as $role) {
            $val = $routing[$role] ?? [];
            $list = is_array($val) ? $val : preg_split('/[,\s]+/', (string) $val);
            $nums = array_values(array_filter(array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), $list)));
            if ($nums) return $nums;
        }
        return [];
    }

    private function send(WhatsAppManager $wa, Tenant $t, array $to, string $msg, string $kind): void
    {
        try {
            $gw = $wa->forTenant($t);
            foreach ($to as $num) {
                $gw->sendText($t->whatsapp_instance, $num, $msg);
                MessageLog::record($t->id, $num, $t->whatsapp_instance, 'out', 'system', $msg, null, null, ['kind' => $kind]);
            }
        } catch (\Throwable $e) {
            $this->warn("send failed for {$t->slug}: {$e->getMessage()}");
        }
    }
}
