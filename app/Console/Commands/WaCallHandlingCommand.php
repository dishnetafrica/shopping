<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\EvolutionAdmin;
use Illuminate\Console\Command;

/**
 * How an Internet provider's WhatsApp line handles calls (DishNet, 18 Nov 2026).
 *
 *   php artisan wa:call-handling 31          decline calls; ShopBot answers the caller and alerts their salesperson
 *   php artisan wa:call-handling 31 --off    calls ring the phone again (ShopBot still alerts the salesperson)
 *   php artisan wa:call-handling 31 --show   only show the current settings
 *
 * The line's own call auto-message (Evolution msgCall) is always emptied: ShopBot sends the caller ONE
 * message itself (MissedCall), logged in the inbox, in the salesperson's name and office-hours aware, instead
 * of Evolution's text going out twice, unseen by the inbox. The webhook gains the CALL event (keeping every
 * event it already has), without which ShopBot never hears about a call.
 */
class WaCallHandlingCommand extends Command
{
    protected $signature = 'wa:call-handling {tenant} {--off : let calls ring the phone again} {--show : change nothing}';

    protected $description = 'Internet provider: decline WhatsApp calls, answer the caller in the chat and alert their salesperson';

    public function handle(EvolutionAdmin $evo): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        $instance = (string) ($t?->whatsapp_instance ?? '');
        if (! $t || $instance === '' || ! $evo->configured()) {
            $this->error('No such business, or it has no Evolution WhatsApp line.');

            return self::FAILURE;
        }
        if (! SalesOwnership::enabled($t)) {
            $this->error("{$t->name} is not an Internet-provider business: missed-call handling is for those only.");

            return self::FAILURE;
        }

        if (! $this->option('show')) {
            $s = $evo->settings($instance);
            if (! $s) {
                $this->error('Could not read the line\'s settings from Evolution — nothing changed.');

                return self::FAILURE;
            }
            $s['rejectCall'] = ! $this->option('off');
            $s['msgCall'] = '';
            $saved = $evo->saveSettings($instance, $s);
            if ($saved['status'] < 200 || $saved['status'] >= 300) {
                $this->error('Evolution refused the settings (' . $saved['status'] . '): ' . mb_substr(json_encode($saved['body']), 0, 300));

                return self::FAILURE;
            }

            $wh = $evo->getWebhook($instance);
            $url = (string) (data_get($wh, 'url') ?? data_get($wh, 'webhook.url') ?? '');
            $evo->setWebhook($instance, $url !== '' ? $url : url('/api/webhook/whatsapp/evolution'), (array) (data_get($wh, 'events') ?? data_get($wh, 'webhook.events') ?? []));
        }

        $s = $evo->settings($instance);
        $wh = $evo->getWebhook($instance);
        $events = array_map('strtoupper', (array) (data_get($wh, 'events') ?? data_get($wh, 'webhook.events') ?? []));
        $this->line("{$t->name}:");
        $this->line('  calls declined (rejectCall): ' . (! empty($s['rejectCall']) ? 'yes' : 'no'));
        $this->line('  Evolution call message:      ' . (trim((string) ($s['msgCall'] ?? '')) === '' ? '(none — ShopBot answers the caller)' : '"' . $s['msgCall'] . '"'));
        $this->line('  webhook:                     ' . (string) (data_get($wh, 'url') ?? data_get($wh, 'webhook.url') ?? '?'));
        $this->line('  webhook sends calls (CALL):  ' . (in_array('CALL', $events, true) ? 'yes' : 'NO — ShopBot will not hear about calls'));
        $this->line('  ShopBot call alerts:         ' . ((bool) $t->setting('isp_call_alerts', true) ? 'on' : 'off (isp_call_alerts)')
            . ', message to caller: ' . ((bool) $t->setting('isp_call_reply', true) ? 'on' : 'off (isp_call_reply)'));

        return self::SUCCESS;
    }
}
