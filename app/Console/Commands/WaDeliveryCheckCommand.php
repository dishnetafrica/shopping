<?php

namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use App\Models\WhatsappChannel;
use App\Services\WhatsApp\DeliveryLag;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Support\BotTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Is any connected WhatsApp line receiving messages late? (DishNet, 24 Nov 2026: 7 hours late for a day while the
 * line said "open".) For every connected Evolution line: the customers' text messages the line holds from the last
 * hours, against what ShopBot received (DeliveryLag). Late → one alert to the business's alert numbers, at most
 * once per line every 2 hours. Read-only otherwise.
 *
 *   php artisan wa:delivery-check            every business (scheduled every 10 minutes)
 *   php artisan wa:delivery-check --tenant=31
 */
class WaDeliveryCheckCommand extends Command
{
    protected $signature = 'wa:delivery-check {--tenant= : only this business id}';

    protected $description = 'Alert when a WhatsApp line receives customer messages late (they reach the line but not ShopBot)';

    public function handle(EvolutionAdmin $evo): int
    {
        if (! $evo->configured()) {
            return self::SUCCESS;
        }
        $q = Tenant::query()->whereNotNull('whatsapp_instance');
        if ($this->option('tenant')) {
            $q->whereKey((int) $this->option('tenant'));
        }
        foreach ($q->get() as $t) {
            if (($t->whatsapp_driver ?: 'evolution') !== 'evolution') {
                continue;
            }
            $lines = array_values(array_unique(array_filter(array_merge(
                [(string) $t->whatsapp_instance],
                WhatsappChannel::where('tenant_id', $t->id)->where('active', true)->where('driver', 'evolution')->pluck('instance')->all()
            ))));
            foreach ($lines as $line) {
                $this->checkLine($t, (string) $line, $evo);
            }
        }

        return self::SUCCESS;
    }

    private function checkLine(Tenant $t, string $line, EvolutionAdmin $evo): void
    {
        try {
            if ($evo->state($line) !== 'open') {
                return;                                     // a line that is down is the connection watchdog's job
            }
            // Every page that still reaches into the window (Codex on #180: a busy line has more than 250).
            $records = [];
            $now = now()->getTimestamp();
            for ($page = 1; $page <= DeliveryLag::MAX_PAGES; $page++) {
                $batch = $evo->findMessages($line, $page, 250);
                $records = array_merge($records, $batch);
                if (! DeliveryLag::morePages($batch, 250, $now)) {
                    break;
                }
            }
            $messages = DeliveryLag::customerTexts($records);
            $ids = array_column($messages, 'id');
            $received = $ids ? array_fill_keys(DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'queued')
                ->whereIn('trace', $ids)->pluck('trace')->all(), true) : [];
            $a = DeliveryLag::assess($messages, $received, $now);
            $this->line("  #{$t->id} {$line}: {$a['checked']} checked, {$a['missing']} not received" . ($a['missing'] ? " (oldest {$a['oldest_min']} min)" : ''));
            if (! DeliveryLag::late($a)) {
                return;
            }
            BotTrace::log($t->id, 'wa', null, 'wa_late', "{$line}: {$a['missing']} not received, oldest {$a['oldest_min']} min");
            if (! Cache::add("wa_late_alert:{$t->id}:{$line}", 1, now()->addHours(2))) {
                return;
            }
            $number = (string) (WhatsappChannel::where('instance', $line)->value('number') ?: $line);
            $text = DeliveryLag::alertText((string) $t->name, $number !== $line ? '+' . ltrim($number, '+') : $line, $a);
            foreach ($t->ownerAlertNumbers() as $n) {
                NotifyOwner::dispatch($t->id, $text, (string) $n);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
