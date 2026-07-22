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

    public int $timeout = 3600;   // long-running by design (throttled)

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
        $stats['targeted']   = count($phones);
        $stats['started_at'] = now()->toIso8601String();
        $c->update(['status' => 'sending', 'stats' => $stats]);

        $sent = 0; $failed = 0; $skipped = 0;
        foreach ($phones as $to) {
            $recipient = \App\Support\Idempotency::recipient((string) $to);
            // 2D — claim this recipient. A retried/restarted job skips anyone already handled.
            if (! \App\Models\CampaignMessage::claim($t->id, $c->id, $recipient)) {
                $skipped++;
                continue;
            }
            try {
                if ($c->image_url) {
                    $gateway->sendImage($inst, $to, $c->image_url, $caption);
                } else {
                    $gateway->sendText($inst, $to, $caption);
                }
                MessageLog::record($t->id, $to, $inst, 'out', 'system', '[campaign] ' . $caption);
                \App\Models\CampaignMessage::markSent($c->id, $recipient, null, 'sent');
                $sent++;
            } catch (\Throwable $e) {
                \App\Models\CampaignMessage::markSent($c->id, $recipient, null, 'failed');
                $failed++;
            }
            // Throttle: 4–9s jitter between messages (ban-risk mitigation).
            usleep(random_int(4, 9) * 1000000);
        }

        $stats['sent']        = $sent;
        $stats['failed']      = $failed;
        $stats['finished_at'] = now()->toIso8601String();
        $c->update(['status' => 'sent', 'stats' => $stats]);
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
