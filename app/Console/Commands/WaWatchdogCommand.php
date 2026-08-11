<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\EvolutionGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * every five minutes for twenty-four days while every single outbound message was rejected by
 * WhatsApp with error 463. The shop's bot wrote perfect replies to 24 days of customers and not
 * one of them was delivered. Nobody found out until somebody happened to notice.
 *
 * "Connected" and "able to deliver" are different facts and only the second one matters. The
 * webhook has been stamping wa_send_ok_at on every delivery receipt and wa_send_err_at on every
 * rejection the whole time. Nothing ever read them. Now something does.
 *
 * The discriminator that makes this safe is deliberate: alarm only when there are RECENT ERRORS
 * *and* no recent success. A quiet shop with no traffic has a stale wa_send_ok_at and nothing
 * wrong with it; alarming on staleness alone would cry wolf every Sunday and get the watchdog
 * muted, and a muted watchdog is worse than none.
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

    /** A delivery rejection this recent means we are actively trying and actively failing. */
    private const DELIVERY_ERR_WINDOW_MIN = 90;
    /** No successful delivery for this long, while errors keep arriving, is a dead number. */
    private const DELIVERY_STALE_HOURS = 6;
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
     * Connected, but is anything actually arriving?
     *
     * Reads the two stamps the webhook has always written:
     *   wa_send_ok_at  — a DELIVERY_ACK / READ came back
     *   wa_send_err_at — WhatsApp rejected a send (status ERROR)
     *
     * Returns true when this tenant is currently undeliverable.
     */
    private function deliveryCheck(Tenant $t, Carbon $now): bool
    {
        $errAt = $this->stamp($t->setting('wa_send_err_at'));
        $okAt  = $this->stamp($t->setting('wa_send_ok_at'));

        // No rejection ever, or none for a while: either healthy or simply quiet. A shop with no
        // traffic must never be alarmed at — that is how a watchdog gets muted.
        if (! $errAt || $errAt->diffInMinutes($now) > self::DELIVERY_ERR_WINDOW_MIN) {
            return $this->deliveryHealthy($t, $now);
        }
        // The most recent thing that happened was a success — a single rejection is normal
        // (blocked contact, number not on WhatsApp) and must not raise an alarm.
        if ($okAt && $okAt->greaterThan($errAt)) {
            return $this->deliveryHealthy($t, $now);
        }
        // Rejections are arriving, but something got through recently enough that the number
        // is clearly still working.
        if ($okAt && $okAt->diffInHours($now) < self::DELIVERY_STALE_HOURS) {
            return $this->deliveryHealthy($t, $now);
        }

        // Errors now, nothing delivered for hours. The number is up and mute.
        $since = $t->setting('wa_delivery_since') ?: ($okAt ? $okAt->toIso8601String() : $now->toIso8601String());
        $t->putSetting('wa_delivery', 'failing');
        $t->putSetting('wa_delivery_since', $since);

        $mutedFor = $okAt ? $okAt->diffForHumans($now, ['syntax' => Carbon::DIFF_ABSOLUTE]) : 'as long as we have records';
        Log::warning('wa.delivery_failing', [
            'tenant' => $t->id, 'last_ok' => $okAt?->toIso8601String(), 'last_err' => $errAt->toIso8601String(),
        ]);

        $lastAlert = $this->stamp($t->setting('wa_delivery_alerted_at'));
        if ($lastAlert && $lastAlert->diffInHours($now) < self::DELIVERY_REALERT_HOURS) return true;
        if (! $this->insideBusinessHours($t, $now)) return true;   // detected now, told at a sane hour

        $t->putSetting('wa_delivery_alerted_at', $now->toIso8601String());

        // NOTE: deliberately NOT NotifyOwner. That sends through the shop's own number, which is
        // precisely the one that cannot deliver — the alert would be swallowed by the fault it is
        // reporting. Same reasoning as WhatsappConnectionAlert's separate alert instance.
        $this->platformPush($t,
            "⚠️ {$t->name}: your WhatsApp is CONNECTED but messages are not reaching customers.\n\n"
            . "Nothing has been delivered for {$mutedFor}. The bot is still replying — those replies "
            . "are being rejected by WhatsApp.\n\nThis needs the operator; reconnecting will not fix it.");

        if (function_exists('Sentry\\captureMessage')) {
            try { \Sentry\captureMessage("WhatsApp delivering nothing for tenant {$t->id} (last ok {$mutedFor} ago)"); }
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
