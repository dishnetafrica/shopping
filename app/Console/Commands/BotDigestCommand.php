<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Daily digest for n8n smart-bot tenants. Run hourly; each tenant fires once at its configured
 * local hour (default 18:00 EAT). Sends a short summary of today's activity to management.
 */
class BotDigestCommand extends Command
{
    protected $signature = 'bot:digest {--force : ignore the hour gate, send now}';
    protected $description = 'Send each n8n tenant a daily WhatsApp activity summary.';

    public function handle(WhatsAppManager $wa): int
    {
        $now = now();
        foreach (Tenant::all() as $t) {
            if ((string) $t->setting('bot_mode', '') !== 'n8n') continue;
            if (! $t->setting('digest_enabled', true)) continue;

            $offset = (int) $t->setting('tz_offset', 3);
            $nowUtc = $now->copy()->utc();
            $local  = $nowUtc->copy()->addHours($offset);
            $hour   = (int) $local->format('G');
            $target = max(0, min(23, (int) $t->setting('digest_hour', 18)));
            if (! $this->option('force') && $hour !== $target) continue;

            // one digest per local day
            $stamp = $local->format('Y-m-d');
            if (! $this->option('force') && ! Cache::add("bot_digest_sent:{$t->id}:{$stamp}", 1, 23 * 3600)) continue;

            // real UTC instant of the tenant's local midnight
            $dayStart = $local->copy()->startOfDay()->subHours($offset);

            $base = Message::where('tenant_id', $t->id)->where('created_at', '>=', $dayStart);
            $in   = (clone $base)->where('direction', 'in')->count();
            $out  = (clone $base)->where('direction', 'out')->count();
            $custs = (clone $base)->where('direction', 'in')->distinct('customer_phone')->count('customer_phone');
            $waiting = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('unread', '>', 0)->count();

            $to = $this->route($t, ['management', 'sales', 'dispatch']);
            if (! $to) continue;

            $msg = "📊 Daily summary — {$local->format('D, j M')}\n"
                 . "Customers: {$custs}\nMessages in: {$in} · out: {$out}\n"
                 . "Still waiting: {$waiting}";

            try {
                $gw = $wa->forTenant($t);
                foreach ($to as $num) {
                    $gw->sendText($t->whatsapp_instance, $num, $msg);
                    MessageLog::record($t->id, $num, $t->whatsapp_instance, 'out', 'system', $msg, null, null, ['kind' => 'digest']);
                }
                $this->info("digest {$t->slug}: sent ({$custs} customers, {$in}/{$out} msgs)");
            } catch (\Throwable $e) {
                $this->warn("digest send failed for {$t->slug}: {$e->getMessage()}");
            }
        }
        return self::SUCCESS;
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
}
