<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\EvolutionGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp connection watchdog. Every active tenant's Evolution instance is checked; a dropped
 * number is silently killing that shop's orders, so we detect it, nudge a reconnect, and — because
 * we obviously can't message a number that's DOWN — raise the alarm where the owner will actually
 * see it: an in-panel banner (window.WA_STATUS, set from tenant settings on page load). If a
 * platform ops WhatsApp number is configured (PLATFORM_WA_INSTANCE) we also push to the owner's
 * personal number from THAT instance. Also watches Evolution capacity so self-serve growth doesn't
 * silently overrun the server.
 *
 * State is stored on the tenant so the panel and /papi can read it without extra queries:
 *   wa_status ('open'|'connecting'|'down'), wa_down_since, wa_last_ok, wa_reconnect_tries, wa_alerted_at
 *   wa_delivery ('ok'|'failing'), wa_delivery_since, wa_delivery_alerted_at
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * 11 Aug 2026 — DELIVERY WATCHDOG.
 *
 * This command asked one question — "is the connection open?" — and Evolution answered "open"
 * every five minutes while WhatsApp refused nine of every thirty replies. "Connected" and "able
 * to answer customers" are different facts and only the second one matters.
 *
 * The first attempt at fixing that judged delivery by whether wa_send_ok_at was recent. It was
 * wrong, and the way it was wrong is worth keeping: that stamp froze on 18 July while the shop
 * went on delivering roughly a thousand messages a day for three more weeks. Evolution's SUCCESS
 * callbacks had stopped matching the in_array($status, ['DELIVERY_ACK','READ','PLAYED']) check in
 * WebhookController; the ERROR branch still matched, so refusals recorded perfectly and successes
 * silently stopped. A watchdog on that stamp would have alarmed daily from 18 July about an
 * outage that did not exist — and been muted long before the real one arrived on 11 August.
 *
 * So the signal is two numbers written on every single message: how many distinct customers we
 * tried to reach, and how many of those were refused. Measured on this shop, an ordinary day
 * refuses 3-5% of customers (wrong numbers, blocks, deleted accounts). The real outage refused
 * 30%. There is no ambiguity between those.
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 */
class WaWatchdogCommand extends Command
{
    protected $signature = 'wa:watchdog';
    protected $description = 'Detect dropped WhatsApp connections and undeliverable numbers, attempt reconnect, and alert owners.';

    /** Minutes a number must stay down before we alarm (rides out brief blips / Evolution restarts). */
    private const GRACE_MIN = 12;
    /** Re-alert at most this often while still down (hours). */
    private const REALERT_HOURS = 6;
    /** Don't nudge a reconnect more than once per this many minutes. */
    private const RECONNECT_EVERY_MIN = 10;

    /** How far back to judge delivery. Stable enough to trust, short enough to notice today. */
    private const DELIVERY_WINDOW_H = 6;
    /** Below this many customers the sample says nothing. */
    private const DELIVERY_MIN_CUSTOMERS = 8;
    /** Fraction of customers refused that means something is genuinely wrong. */
    private const DELIVERY_BROKEN_RATIO = 0.25;
    /** Re-alert at most this often while delivery is still broken (hours). */
    private const DELIVERY_REALERT_HOURS = 12;

    public function handle(EvolutionAdmin $evo): int
    {
        if (! method_exists($evo, 'configured') || ! $evo->configured()) {
            $this->info('Evolution not configured — skipping.');
            return self::SUCCESS;
        }

        $now      = now();
        $checked  = 0;
        $down     = 0;
        $undeliv  = 0;
        $liveInst = 0;

        foreach (Tenant::all() as $t) {
            $instance = (string) ($t->whatsapp_instance ?? '');
            $driver   = (string) ($t->whatsapp_driver ?? 'evolution');
            if ($instance === '' || $driver !== 'evolution') continue;

            $checked++;
            $liveInst++;
            $state = $evo->state($instance);   // open | connecting | close | missing

            // Failover: which number should PROACTIVE sends use? The primary when it's up; otherwise
            // the first connected additional channel, so order acks / alerts keep flowing while the
            // main number is down. Recorded on the tenant; Tenant::sendInstance() reads it.
            $sendInstance = $instance;
            if ($state !== 'open') {
                foreach ($t->whatsappChannels()->where('active', true)->where('is_primary', false)->get() as $ch) {
                    if ($ch->instance && $evo->state($ch->instance) === 'open') { $sendInstance = $ch->instance; break; }
                }
            }
            $t->putSetting('wa_send_instance', $sendInstance !== $instance ? $sendInstance : '');

            if ($state === 'open') {
                $this->onHealthy($t, $now);
                // A number can be perfectly connected and still deliver nothing. Only worth asking
                // when the link is up: if it's down, that is already the headline.
                if ($this->deliveryCheck($t, $now)) $undeliv++;
                continue;
            }
            if ($state === 'connecting') {
                // Transient — record it but don't alarm yet.
                $t->putSetting('wa_status', 'connecting');
                continue;
            }

            // close | missing | null(unreachable) → treat as DOWN.
            $down++;
            $this->onDown($t, $instance, $state ?? 'unknown', $evo, $now);
        }

        $this->capacityCheck($liveInst);

        // Additional (non-primary) numbers: check + nudge each, without touching the tenant-level
        // banner (that reflects the PRIMARY number, since orders keep flowing there).
        $extra = 0; $extraDown = 0;
        foreach (\App\Models\WhatsappChannel::where('is_primary', false)->where('active', true)->get() as $c) {
            if (($c->driver ?? 'evolution') !== 'evolution' || ! $c->instance) continue;
            $extra++;
            $st = $evo->state($c->instance);
            if ($st === 'open' || $st === 'connecting') continue;
            $extraDown++;
            $throttle = "wa:reconnect:ch:{$c->id}";
            if (Cache::add($throttle, 1, self::RECONNECT_EVERY_MIN * 60)) {
                try { $evo->qr($c->instance); Log::info('wa.ch_reconnect_nudge', ['channel' => $c->id, 'tenant' => $c->tenant_id]); }
                catch (\Throwable $e) { Log::warning('wa.ch_reconnect_failed', ['channel' => $c->id, 'err' => $e->getMessage()]); }
            }
        }
        if ($extra) $this->info("wa:watchdog also checked {$extra} extra numbers, {$extraDown} down.");

        $this->info("wa:watchdog checked {$checked} instances, {$down} down, {$undeliv} connected-but-undeliverable.");
        return self::SUCCESS;
    }

    /**
     * Connected, but are customers actually being answered?
     *
     * NOT judged on wa_send_ok_at. That stamp froze on 18 Jul 2026 while this shop went on
     * delivering roughly a thousand messages a day for three more weeks — Evolution's SUCCESS
     * callbacks stopped matching the in_array($status, ['DELIVERY_ACK','READ','PLAYED']) check
     * in WebhookController, while the ERROR branch kept matching. A watchdog built on that stamp
     * would have alarmed every day since 18 July about an outage that did not exist, and then
     * been muted long before the real one arrived on 11 August.
     *
     * So the signal is two numbers written on every single message: distinct customers we tried
     * to reach, and distinct customers who were refused.
     *
     *   measured on this shop, ordinary days   3-5% of customers refused (bad numbers, blocks)
     *   measured during the real outage        9 of 30 customers, 30%
     *
     * Returns true when this tenant is currently undeliverable.
     */
    private function deliveryCheck(Tenant $t, Carbon $now): bool
    {
        $since = $now->copy()->subHours(self::DELIVERY_WINDOW_H);
        $messaged = (int) DB::table('messages')->where('tenant_id', $t->id)
            ->where('direction', 'out')->where('created_at', '>=', $since)
            ->distinct()->count('customer_phone');

        // A quiet shop is not a broken one. Alarming on a thin sample is how a watchdog earns
        // its way onto the ignore list, and an ignored watchdog is worse than none.
        if ($messaged < self::DELIVERY_MIN_CUSTOMERS) return $this->deliveryHealthy($t, $now);

        $rejected = min((int) DB::table('bot_events')->where('tenant_id', $t->id)
            ->where('stage', 'send_failed')->where('created_at', '>=', $since)
            ->distinct()->count('phone'), $messaged);

        $ratio = $rejected / $messaged;
        if ($ratio < self::DELIVERY_BROKEN_RATIO) return $this->deliveryHealthy($t, $now);

        $since_str = $t->setting('wa_delivery_since') ?: $now->toIso8601String();
        $t->putSetting('wa_delivery', 'failing');
        $t->putSetting('wa_delivery_since', $since_str);

        Log::warning('wa.delivery_failing', [
            'tenant' => $t->id, 'messaged' => $messaged, 'rejected' => $rejected,
            'ratio' => round($ratio, 3),
        ]);

        $lastAlert = $this->stamp($t->setting('wa_delivery_alerted_at'));
        if ($lastAlert && $lastAlert->diffInHours($now) < self::DELIVERY_REALERT_HOURS) return true;
        if (! $this->insideBusinessHours($t, $now)) return true;   // detected now, told at a sane hour

        $t->putSetting('wa_delivery_alerted_at', $now->toIso8601String());

        // NOT NotifyOwner: that sends through the shop's own number, which is the one failing —
        // the alert would be swallowed by the fault it reports.
        $this->platformPush($t, sprintf(
            "⚠️ %s: %d of your last %d customers did NOT receive their reply.

"
            . "WhatsApp is connected and the bot is answering — those answers are being refused.

"
            . "This needs the operator. Do NOT delete and recreate the WhatsApp connection: a "
            . "fresh session makes this worse, not better.",
            $t->name, $rejected, $messaged));

        if (function_exists('Sentry\\captureMessage')) {
            try { \Sentry\captureMessage("WhatsApp refusing {$rejected}/{$messaged} for tenant {$t->id}"); }
            catch (\Throwable $e) {}
        }

        return true;
    }

    /** Clear the failing state, and say so once if it had been failing. */
    private function deliveryHealthy(Tenant $t, Carbon $now): bool
    {
        if ((string) $t->setting('wa_delivery', 'ok') === 'failing') {
            $t->putSetting('wa_delivery_alerted_at', null);
            $t->putSetting('wa_delivery_since', null);
            // Delivery works again, so the shop's own number can carry the good news.
            try {
                \App\Jobs\NotifyOwner::dispatch($t->id,
                    "✅ Your WhatsApp is delivering to customers again.");
            } catch (\Throwable $e) { /* best-effort */ }
            Log::info('wa.delivery_recovered', ['tenant' => $t->id]);
        }
        $t->putSetting('wa_delivery', 'ok');
        return false;
    }

    /** Tenant-local business hours, so a 3am detection becomes a 7am message. */
    private function insideBusinessHours(Tenant $t, Carbon $now): bool
    {
        $offset = (int) $t->setting('tz_offset', 3);            // EAT by default
        $hour   = ((int) $now->copy()->utc()->format('G') + $offset + 24) % 24;
        return $hour >= 7 && $hour < 21;
    }

    private function stamp($value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        try { return Carbon::parse($value); } catch (\Throwable $e) { return null; }
    }

    private function onHealthy(Tenant $t, \Illuminate\Support\Carbon $now): void
    {
        $wasDown = (string) $t->setting('wa_status', 'open') === 'down';
        $t->putSetting('wa_status', 'open');
        $t->putSetting('wa_last_ok', $now->toIso8601String());
        $t->putSetting('wa_down_since', null);
        $t->putSetting('wa_reconnect_tries', 0);

        if ($wasDown) {
            // It just recovered — the number is back up, so a WhatsApp ping to the owner works now.
            $t->putSetting('wa_alerted_at', null);
            try {
                \App\Jobs\NotifyOwner::dispatch($t->id, "✅ Your shop's WhatsApp is reconnected and taking orders again.");
            } catch (\Throwable $e) { /* best-effort */ }
            Log::info('wa.recovered', ['tenant' => $t->id]);
        }
    }

    private function onDown(Tenant $t, string $instance, string $state, EvolutionAdmin $evo, \Illuminate\Support\Carbon $now): void
    {
        // Mark down + stamp when it started.
        $t->putSetting('wa_status', 'down');
        $downSince = $t->setting('wa_down_since');
        if (! $downSince) {
            $t->putSetting('wa_down_since', $now->toIso8601String());
            $downSince = $now->toIso8601String();
        }

        // Nudge a reconnect (throttled). For a 'close' state with valid creds, /instance/connect
        // often revives the session without a fresh QR scan.
        $throttle = "wa:reconnect:{$t->id}";
        if (Cache::add($throttle, 1, self::RECONNECT_EVERY_MIN * 60)) {
            try {
                $evo->qr($instance);   // triggers connect; if creds are gone the owner must rescan
                $t->putSetting('wa_reconnect_tries', (int) $t->setting('wa_reconnect_tries', 0) + 1);
                Log::info('wa.reconnect_nudge', ['tenant' => $t->id, 'state' => $state]);
            } catch (\Throwable $e) {
                Log::warning('wa.reconnect_failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
            }
        }

        // Alarm only after the grace window, and not more often than REALERT_HOURS.
        $downMin = $now->diffInMinutes(\Illuminate\Support\Carbon::parse($downSince));
        if ($downMin < self::GRACE_MIN) return;

        $lastAlert = $t->setting('wa_alerted_at');
        if ($lastAlert && \Illuminate\Support\Carbon::parse($lastAlert)->diffInHours($now) < self::REALERT_HOURS) {
            return;
        }
        $t->putSetting('wa_alerted_at', $now->toIso8601String());

        // The in-panel banner is already armed (wa_status=down → window.WA_STATUS). Optionally also
        // push to the owner's personal number from a platform ops instance, if one is configured.
        $this->platformPush($t,
            "⚠️ {$t->name}: your shop's WhatsApp has disconnected and is not taking orders. "
            . "Open your panel → Setup to reconnect (scan the QR).");

        Log::warning('wa.down_alert', ['tenant' => $t->id, 'down_min' => $downMin, 'state' => $state]);
        if (function_exists('Sentry\\captureMessage')) {
            try { \Sentry\captureMessage("WhatsApp down for tenant {$t->id} ({$downMin}m)"); } catch (\Throwable $e) {}
        }
    }

    /**
     * Best-effort push to the owner's own number via a platform ops instance (if configured).
     * Takes the message, because the two things it reports — a dead link and a mute number —
     * need very different words and lead to very different actions.
     */
    private function platformPush(Tenant $t, string $message): void
    {
        $opsInstance = (string) env('PLATFORM_WA_INSTANCE', '');
        if ($opsInstance === '') {
            Log::warning('wa.no_ops_instance', ['tenant' => $t->id]);
            return;
        }

        $owner = \App\Models\User::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->first();

        $recipients = array_filter([
            $owner?->phone ?: (string) $t->whatsapp_number,
            (string) config('whatsapp.operator_alert_phone', ''),
        ]);
        $recipients = array_values(array_unique(array_map(fn ($p) => preg_replace('/\D/', '', (string) $p), $recipients)));
        if (! $recipients) return;

        foreach ($recipients as $to) {
            if ($to === '') continue;
            try {
                app(EvolutionGateway::class)->sendText($opsInstance, $to, $message);
            } catch (\Throwable $e) {
                Log::warning('wa.platform_push_failed', ['tenant' => $t->id, 'to' => $to, 'err' => $e->getMessage()]);
            }
        }
    }

    /** Warn ops when Evolution is nearing its instance ceiling (self-serve growth guard). */
    private function capacityCheck(int $liveInstances): void
    {
        $cap = (int) env('EVOLUTION_MAX_INSTANCES', 0);   // 0 = disabled
        if ($cap <= 0) {
            Cache::forget('wa:capacity_warn');
            return;
        }
        $pct = (int) round($liveInstances / $cap * 100);
        if ($liveInstances >= $cap * 0.8) {
            Cache::put('wa:capacity_warn', ['instances' => $liveInstances, 'cap' => $cap, 'pct' => $pct], 3600);
            Log::warning('wa.capacity', ['instances' => $liveInstances, 'cap' => $cap, 'pct' => $pct]);
            if (function_exists('Sentry\\captureMessage')) {
                try { \Sentry\captureMessage("Evolution capacity {$pct}% ({$liveInstances}/{$cap})"); } catch (\Throwable $e) {}
            }
        } else {
            Cache::forget('wa:capacity_warn');
        }
    }
}
