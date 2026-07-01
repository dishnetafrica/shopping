<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\EvolutionGateway;
use Illuminate\Console\Command;
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
 */
class WaWatchdogCommand extends Command
{
    protected $signature = 'wa:watchdog';
    protected $description = 'Detect dropped WhatsApp connections, attempt reconnect, and alert owners.';

    /** Minutes a number must stay down before we alarm (rides out brief blips / Evolution restarts). */
    private const GRACE_MIN = 12;
    /** Re-alert at most this often while still down (hours). */
    private const REALERT_HOURS = 6;
    /** Don't nudge a reconnect more than once per this many minutes. */
    private const RECONNECT_EVERY_MIN = 10;

    public function handle(EvolutionAdmin $evo): int
    {
        if (! method_exists($evo, 'configured') || ! $evo->configured()) {
            $this->info('Evolution not configured — skipping.');
            return self::SUCCESS;
        }

        $now      = now();
        $checked  = 0;
        $down     = 0;
        $liveInst = 0;

        foreach (Tenant::all() as $t) {
            $instance = (string) ($t->whatsapp_instance ?? '');
            $driver   = (string) ($t->whatsapp_driver ?? 'evolution');
            if ($instance === '' || $driver !== 'evolution') continue;

            $checked++;
            $liveInst++;
            $state = $evo->state($instance);   // open | connecting | close | missing

            if ($state === 'open') {
                $this->onHealthy($t, $now);
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

        $this->info("wa:watchdog checked {$checked} instances, {$down} down.");
        return self::SUCCESS;
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
        $this->platformPush($t);

        Log::warning('wa.down_alert', ['tenant' => $t->id, 'down_min' => $downMin, 'state' => $state]);
        if (function_exists('Sentry\\captureMessage')) {
            try { \Sentry\captureMessage("WhatsApp down for tenant {$t->id} ({$downMin}m)"); } catch (\Throwable $e) {}
        }
    }

    /** Best-effort push to the owner's own number via a platform ops instance (if configured). */
    private function platformPush(Tenant $t): void
    {
        $opsInstance = (string) env('PLATFORM_WA_INSTANCE', '');
        if ($opsInstance === '') return;

        $owner = \App\Models\User::withoutGlobalScopes()
            ->where('tenant_id', $t->id)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->first();
        $phone = $owner?->phone ?: (string) $t->whatsapp_number;
        if (! $phone) return;

        try {
            app(EvolutionGateway::class)->sendText(
                $opsInstance,
                preg_replace('/\D/', '', $phone),
                "⚠️ {$t->name}: your shop's WhatsApp has disconnected and is not taking orders. "
                . "Open your panel → Setup to reconnect (scan the QR)."
            );
        } catch (\Throwable $e) {
            Log::warning('wa.platform_push_failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
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
