<?php
namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Marketing\AudienceResolver;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a marketing campaign to its audience, ONE message at a time with a
 * randomised delay between sends. Broadcasting fast on an unofficial WhatsApp
 * connection is the quickest way to get a number banned — the throttle is a
 * deliberate safety measure, not a performance bug.
 */
class SendCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * v4.49 — this used to be one 3600s job for the whole list. At a deliberate 4-9s throttle plus
     * the send itself, ~15s a message, an hour buys about 240 of them: a 666-person campaign was
     * being cut off two-thirds of the way through and the run either died or was retried, while
     * `stats['sent']` — a LOCAL counter that resets every attempt — recorded only the last
     * attempt's share against the full audience. That is why campaigns read 236/666.
     *
     * Now the job sends for a bounded stretch, then hands off to a fresh copy of itself. The
     * recipient claim already makes that safe: a re-dispatched job skips everyone handled and
     * carries on where the last one stopped. Status stays 'sending' until the list is genuinely
     * exhausted, so "Sent" starts meaning sent.
     */
    public int $timeout = 900;

    /** Stop sending and hand off after this long, well inside the job timeout. */
    private const BUDGET_SECONDS = 600;

    public function __construct(public int $tenantId, public int $campaignId) {}

    public function handle(TenantContext $ctx, WhatsAppManager $wa, AudienceResolver $aud): void
    {
        $ctx->set($this->tenantId);

        $t = Tenant::find($this->tenantId);
        $c = Campaign::find($this->campaignId);
        if (! $t || ! $t->whatsapp_instance || ! $c) return;

        $phones  = $aud->phones($c->audience, $c->category);
        $caption = $this->compose($c, $t);
        $gateway = $wa->forTenant($t);
        $inst = $t->sendInstance();

        $stats = is_array($c->stats) ? $c->stats : [];
        $stats['targeted'] = count($phones);
        if (empty($stats['started_at'])) {
            $stats['started_at'] = now()->toIso8601String();   // the FIRST chunk starts the clock
        }
        $c->update(['status' => 'sending', 'stats' => $stats]);

        // Per-campaign daily batching — "send X/day, spread over the week." 0/null = send straight
        // through as before. When a day's share is spent, hand off to a copy delayed to the next
        // tenant-local midnight, so a big audience trickles out over days instead of a single blast.
        $perDay  = (int) ($c->daily_send_cap ?? 0);
        $sentDay = $perDay > 0 ? self::sentToday($c->id, $t) : 0;
        if ($perDay > 0 && $sentDay >= $perDay) {
            $c->update(['status' => 'sending', 'stats' => array_merge($stats, self::tally($c->id))]);
            self::dispatch($this->tenantId, $this->campaignId)->delay(self::nextDayStart($t));

            return;
        }

        $startedAt = time();
        $moreToDo  = false;
        $dayCapHit = false;
        foreach ($phones as $to) {
            // Hand off rather than run to the timeout. Everything already claimed is skipped
            // instantly by the next copy, so it resumes exactly where this one stopped.
            if (time() - $startedAt >= self::BUDGET_SECONDS) { $moreToDo = true; break; }
            if ($perDay > 0 && $sentDay >= $perDay) { $dayCapHit = true; $moreToDo = true; break; }
            $recipient = \App\Support\Idempotency::recipient((string) $to);
            // 2D — claim this recipient. A retried/restarted job skips anyone already handled.
            if (! \App\Models\CampaignMessage::claim($t->id, $c->id, $recipient)) {
                continue;   // an earlier chunk already handled this person
            }
            try {
                if ($c->image_url) {
                    $gateway->sendImage($inst, $to, $c->image_url, $caption);
                } else {
                    $gateway->sendText($inst, $to, $caption);
                }
                MessageLog::record($t->id, $to, $inst, 'out', 'system', '[campaign] ' . $caption);
                \App\Models\CampaignMessage::markSent($c->id, $recipient, null, 'sent');
            } catch (\Throwable $e) {
                \App\Models\CampaignMessage::markSent($c->id, $recipient, null, 'failed');
            }
            $sentDay++;   // an attempt this day counts toward the daily cap (a message left the building)
            // Throttle: 4–9s jitter between messages (ban-risk mitigation).
            usleep(random_int(4, 9) * 1000000);
        }

        // Counted from campaign_messages, never from a local variable. A local counter restarts at
        // zero on every chunk and every retry, so the last attempt's total was overwriting the real
        // one — the single reason a campaign that reached most of its audience reported a third of it.
        $stats = array_merge($stats, self::tally($c->id));

        if ($dayCapHit) {
            // Today's batch is done and the audience isn't exhausted — resume at the next tenant-local day.
            $c->update(['status' => 'sending', 'stats' => $stats]);
            self::dispatch($this->tenantId, $this->campaignId)->delay(self::nextDayStart($t));

            return;
        }
        if ($moreToDo) {
            $c->update(['status' => 'sending', 'stats' => $stats]);
            self::dispatch($this->tenantId, $this->campaignId)->delay(now()->addSeconds(5));

            return;
        }

        $stats['finished_at'] = now()->toIso8601String();
        $c->update(['status' => 'sent', 'stats' => $stats]);
    }

    /**
     * The real numbers, straight from the per-recipient ledger.
     *
     * `pending` is a claimed recipient whose send never resolved — a job killed mid-flight. It is
     * surfaced rather than folded into anything else, because a number nobody can explain is how a
     * campaign report stops being believed.
     */
    /** Attempts claimed for this campaign since the tenant's local midnight — the daily-batch meter. */
    public static function sentToday(int $campaignId, Tenant $t): int
    {
        $tz = (string) ($t->setting('timezone', config('app.timezone', 'UTC')) ?: 'UTC');
        try {
            $start = \Illuminate\Support\Carbon::now($tz)->startOfDay();
        } catch (\Throwable $e) {
            $start = \Illuminate\Support\Carbon::now()->startOfDay();
        }

        return (int) \Illuminate\Support\Facades\DB::table('campaign_messages')
            ->where('campaign_id', $campaignId)
            ->where('created_at', '>=', $start)
            ->count();
    }

    /** The next tenant-local midnight, as a delay target for resuming tomorrow's batch. */
    public static function nextDayStart(Tenant $t): \Illuminate\Support\Carbon
    {
        $tz = (string) ($t->setting('timezone', config('app.timezone', 'UTC')) ?: 'UTC');
        try {
            return \Illuminate\Support\Carbon::now($tz)->addDay()->startOfDay();
        } catch (\Throwable $e) {
            return \Illuminate\Support\Carbon::now()->addDay()->startOfDay();
        }
    }

    public static function tally(int $campaignId): array
    {
        $by = \Illuminate\Support\Facades\DB::table('campaign_messages')
            ->where('campaign_id', $campaignId)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'sent'    => (int) ($by['sent'] ?? 0),
            'failed'  => (int) ($by['failed'] ?? 0),
            'pending' => (int) ($by['pending'] ?? 0),
        ];
    }

    protected function compose(Campaign $c, ?Tenant $t = null): string
    {
        // Show prices in the tenant's OWN currency (what $p->price is stored in) instead of a
        // hardcoded "UGX" — a native-USD shop must read "USD 33", not "UGX 33". This mirrors
        // CapabilityContext::nativeCurrency() so the send matches the v3 live preview exactly.
        [$cur, $dec] = self::priceCurrency($t);

        $lines = [];
        $message = trim((string) $c->message);
        if ($message !== '') $lines[] = $message;

        if (is_array($c->product_ids) && $c->product_ids) {
            // Keep the exact order the operator attached them in (matches the v3 live
            // WhatsApp preview) — whereIn() alone would reorder by primary key.
            $prods = Product::whereIn('id', $c->product_ids)->get()->keyBy('id');
            foreach ($c->product_ids as $pid) {
                $p = $prods->get((int) $pid);
                if ($p) $lines[] = '• ' . $p->name . ' — ' . $cur . ' ' . number_format((float) $p->price, $dec);
            }
        }

        $cta = trim((string) $c->cta);
        if ($cta !== '') {
            $lines[] = '';
            $lines[] = $cta;
        }
        return implode("\n", $lines);
    }

    /**
     * The currency code + decimal places to LABEL a product's price with in a campaign.
     *
     * A product's stored `price` is always in the tenant's BASE currency (the catalogue's own
     * currency): a USD shop stores 66 = $66, a UGX shop stores 165000 = UGX 165,000. So the
     * campaign simply labels with the base currency — no rate conversion, no dependence on the
     * currency_basis flag. This is what stops "$66" being shown to customers as "UGX 66".
     *
     * @return array{0:string,1:int}
     */
    public static function priceCurrency(?Tenant $t): array
    {
        if (! $t) return ['UGX', 0];
        $base = strtoupper(trim((string) $t->setting('currency', 'UGX')));
        if ($base === '' || $base === 'AUTO') $base = 'UGX';
        return [$base, \App\Services\Pricing::decimalsForCurrency($base)];
    }
}
