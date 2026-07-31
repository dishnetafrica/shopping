<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\Bot\PriceListService;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The "virtual salesperson" — a governed, multi-touch WhatsApp follow-up cadence so a human rep is
 * freed to visit customers in person. Two tracks, both opt-in per tenant (sales_followup_enabled):
 *
 *  ENQUIRY cadence (someone asked but hasn't ordered):
 *    Day 1  → follow up on the items they asked about + offer a quote
 *    Day 3  → send the latest price list (PDF)
 *    Day 7  → friendly check-in
 *    +14d   → periodic price-list touch (capped)
 *
 *  REORDER reminder (past buyers): when their last order is older than sales_reorder_days, nudge
 *    them ("it's been N days since your last order (…) — ready for a fresh supply?").
 *
 * Guardrails (so it never feels like spam): office-hours only (schedule), never while a human has
 * taken over / mid-cart / do-not-contact, one touch per customer per run, stops the moment they
 * reply or place an order, and every message is personal (name + their items). Reason-coded outbound.
 */
class SalesCadenceCommand extends Command
{
    protected $signature = 'bot:sales-cadence {--dry : list who would be messaged, send nothing}';
    protected $description = 'Salesperson-style WhatsApp follow-ups: enquiry cadence + reorder reminders';

    private array $tenantCache = [];

    public function handle(WhatsAppManager $wa, TenantContext $ctx): int
    {
        $dry  = (bool) $this->option('dry');
        $sent = 0;

        Conversation::withoutGlobalScopes()
            ->whereNotNull('customer_phone')
            ->where('agent_active', false)
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>=', now()->subDays(120))   // outer bound
            ->orderBy('last_inbound_at')
            ->limit(800)->get()
            ->each(function (Conversation $c) use ($wa, $ctx, $dry, &$sent) {
                if ($sent >= 250) return;                             // burst cap per run
                try {
                    $tenant = $this->tenant($c->tenant_id);
                    if (! $tenant) return;
                    if (! (bool) $tenant->setting('sales_followup_enabled', false)) return;
                    $ctx->set($tenant->id);

                    $state = (array) $c->state;
                    if (! empty($state['do_not_contact'])) return;
                    if (! empty($state['sales_opt_out'])) return;
                    if ((int) ($state['human_until'] ?? 0) > time()) return;
                    if (! empty($c->cart)) return;                    // mid-cart — don't interrupt

                    $phone = preg_replace('/[^0-9+]/', '', (string) $c->customer_phone);
                    if ($phone === '' || ! $tenant->whatsapp_instance) return;

                    $touch = $this->decideTouch($tenant, $c, $state, $phone);
                    if (! $touch) return;

                    if ($dry) { $this->line("[dry] {$phone} → {$touch['kind']}"); $sent++; return; }

                    $g = $wa->forTenant($tenant);
                    $ok = $this->sendTouch($tenant, $g, $phone, $touch);
                    if (! $ok) return;

                    // Persist the new cadence state on the conversation.
                    $state = array_merge($state, $touch['state']);
                    $c->state = $state;
                    $c->save();
                    MessageLog::record($tenant->id, $phone, $tenant->whatsapp_instance, 'out', 'bot',
                        $touch['log'], null, null, ['via' => 'sales', 'reason' => $touch['reason']]);
                    BotTrace::log($tenant->id, 'sales', $phone, $touch['kind']);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('bot:sales-cadence conv ' . $c->id . ': ' . $e->getMessage());
                }
            });

        $this->info("bot:sales-cadence — " . ($dry ? 'would send' : 'sent') . " {$sent}.");
        return self::SUCCESS;
    }

    /**
     * Decide the single touch (if any) due for this customer now. Returns null or:
     *  ['kind','reason','log','sendPriceList'=>bool,'text'=>?,'state'=>[...]]
     */
    private function decideTouch(Tenant $t, Conversation $c, array $state, string $phone): ?array
    {
        $now      = now();
        $lastIn   = $c->last_inbound_at;                                   // customer's last message
        $repliedByUs = $c->last_message_at && $lastIn && $c->last_message_at->gt($lastIn);
        $sales    = (array) ($state['sales'] ?? []);
        $stage    = (int) ($sales['stage'] ?? 0);
        $lastTouch = ! empty($sales['last_at']) ? \Illuminate\Support\Carbon::parse($sales['last_at']) : null;

        // If the customer messaged AFTER our last proactive touch, they re-engaged — the bot is
        // handling them; pause the proactive cadence (don't talk over a live thread).
        if ($lastTouch && $lastIn && $lastIn->gt($lastTouch)) {
            return null;
        }

        $ctx = $this->customerContext($t, $phone);

        // --- ENQUIRY CADENCE (they asked, no order yet) -------------------------------------
        if ($ctx['orderCount'] === 0 && $repliedByUs && $lastIn) {
            $ageMin = $lastIn->diffInMinutes($now);
            // stage 0 -> Day 1 (>= ~1 day quiet, but not ancient)
            if ($stage === 0 && $ageMin >= 60 * 22 && $ageMin <= 60 * 24 * 4) {
                $items = $ctx['interest'] !== '' ? $ctx['interest'] : 'the items you asked about';
                return $this->mk('enquiry_day1', 'SALES_DAY1',
                    $this->tpl($t, 'sales_msg_day1', "Hi {name} 👋 Following up on {items} — would you like me to prepare a quote or arrange delivery? Happy to help.", $ctx, $items),
                    false, ['sales' => ['stage' => 1, 'last_at' => $now->toIso8601String()]]);
            }
            if ($stage === 1 && $lastTouch && $lastTouch->diffInHours($now) >= 44) {   // Day 3
                return $this->mk('enquiry_pricelist', 'SALES_PRICELIST',
                    $this->tpl($t, 'sales_msg_pricelist', "Hi {name} 👋 Here's our latest price list — just tell me the items and quantities and I'll arrange delivery.", $ctx, ''),
                    true, ['sales' => ['stage' => 2, 'last_at' => $now->toIso8601String()]]);
            }
            if ($stage === 2 && $lastTouch && $lastTouch->diffInHours($now) >= 24 * 4) {  // Day 7
                return $this->mk('enquiry_day7', 'SALES_DAY7',
                    $this->tpl($t, 'sales_msg_day7', "Hi {name} 👋 Just checking in — anything you'd like to stock up on this week? I can send prices or arrange delivery.", $ctx, ''),
                    false, ['sales' => ['stage' => 3, 'last_at' => $now->toIso8601String()]]);
            }
            // periodic price list every N days, capped
            $periodDays = max(3, (int) $t->setting('sales_price_list_days', 14));
            if ($stage >= 3 && $stage < 9 && $lastTouch && $lastTouch->diffInDays($now) >= $periodDays) {
                return $this->mk('enquiry_periodic', 'SALES_PERIODIC',
                    $this->tpl($t, 'sales_msg_pricelist', "Hi {name} 👋 Here's our updated price list — order anytime and we'll deliver.", $ctx, ''),
                    true, ['sales' => ['stage' => $stage + 1, 'last_at' => $now->toIso8601String()]]);
            }
            return null;
        }

        // --- REORDER REMINDER (past buyers) -------------------------------------------------
        if ($ctx['orderCount'] > 0 && (bool) $t->setting('sales_include_buyers', true)) {
            $reorderDays = max(3, (int) $t->setting('sales_reorder_days', 21));
            $lastOrder   = $ctx['lastOrderAt'];
            if (! $lastOrder) return null;
            // Carbon 3's diffInDays returns a FLOAT — a customer literally received
            // "It's been 10.005576970544 days since your last order" (real incident).
            $daysSince = (int) floor($lastOrder->diffInDays($now));
            if ($daysSince < $reorderDays) return null;

            // throttle: at most one reorder nudge per reorder window
            $lastReorder = ! empty($sales['reorder_at']) ? \Illuminate\Support\Carbon::parse($sales['reorder_at']) : null;
            if ($lastReorder && $lastReorder->diffInDays($now) < $reorderDays) return null;

            $items = $ctx['lastItems'] !== '' ? (' (' . $ctx['lastItems'] . ')') : '';
            $msg = $this->tpl($t, 'sales_msg_reorder',
                "Hi {name} 👋 It's been {days} days since your last order{items}. Ready for a fresh supply? I can send today's prices and arrange delivery.",
                $ctx, '', ['days' => (string) $daysSince, 'items' => $items]);
            $sales['reorder_at'] = $now->toIso8601String();
            return $this->mk('reorder', 'SALES_REORDER', $msg, false, ['sales' => $sales]);
        }

        return null;
    }

    private function mk(string $kind, string $reason, string $text, bool $priceList, array $state): array
    {
        return ['kind' => $kind, 'reason' => $reason, 'text' => $text, 'sendPriceList' => $priceList,
                'log' => ($priceList ? '[price list] ' : '') . $text, 'state' => $state];
    }

    /** Send the message (+ price-list PDF when the touch calls for it). Returns false on hard failure. */
    private function sendTouch(Tenant $t, $g, string $phone, array $touch): bool
    {
        try {
            if (! empty($touch['text'])) {
                $g->sendText($t->whatsapp_instance, $phone, $touch['text']);
                \App\Models\CustomerProfile::withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $t->id, 'phone' => $phone], []
                )->forceFill(['last_contacted_at' => now()])->save();   // V4.1 global touch cap sees cadence too
            }
            if (! empty($touch['sendPriceList'])) {
                $doc = ((string) $t->setting('price_list_style', 'photos') === 'photos')
                    ? app(\App\Services\Catalog\CatalogRenderer::class)->forTenant($t) : null;
                if (! $doc) $doc = app(PriceListService::class)->available() ? app(PriceListService::class)->generate($t) : null;
                if ($doc && ! empty($doc['b64']) && method_exists($g, 'sendDocument')) {
                    $g->sendDocument($t->whatsapp_instance, $phone, $doc['b64'], $doc['fileName'] ?? 'Price-List.pdf', 'Our latest price list 📄');
                } else {
                    // no PDF engine/products → fall back to a configured link if present
                    $link = trim((string) $t->setting('sales_price_list_link', ''));
                    if ($link !== '') $g->sendText($t->whatsapp_instance, $phone, $link);
                }
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('sales-cadence send failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Name + interest + order history for a phone (best-effort, all tenant-scoped). */
    private function customerContext(Tenant $t, string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        $orders = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->orderByDesc('id')->limit(20)->get();
        $name = '';
        foreach ($orders as $o) { if (trim((string) $o->customer_name) !== '') { $name = trim((string) $o->customer_name); break; } }

        $lastOrderAt = null; $lastItems = '';
        $last = $orders->first();
        if ($last) {
            $lastOrderAt = $last->delivered_at ?: $last->created_at;
            $lastItems   = $this->humanItems((string) ($last->items_text ?? ''));
        }

        // Interest: an open lead's interest, else the last thing they were shown/asked.
        $interest = '';
        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->whereIn('status', Lead::OPEN_STATUSES)->orderByDesc('id')->first();
        if ($lead) {
            $interest = trim((string) ($lead->interest ?: ''));
            if ($name === '' && trim((string) $lead->customer_name) !== '') $name = trim((string) $lead->customer_name);
        }

        return [
            'name'       => $this->firstName($name),
            'interest'   => $interest,
            'orderCount' => $orders->count(),
            'lastOrderAt'=> $lastOrderAt,
            'lastItems'  => $lastItems,
        ];
    }

    /**
     * A human greets "Hi Abdifatah" — never "Hi Abdifatah Ahmed Shop No 48" (real incident:
     * WhatsApp contact names carry shop labels). Take the leading name words, stop at
     * shop/number tokens, max two words.
     */
    private function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $out = [];
        foreach (preg_split('/\s+/u', $name) as $w) {
            if (preg_match('/^(shop|store|stall|duka|no\.?|nr\.?|#|\p{N})/iu', $w)) {
                break;   // "Shop No 48" and friends are labels, not names
            }
            $out[] = $w;
            if (count($out) >= 2) {
                break;
            }
        }

        $first = $out ? $out[0] : $name;   // first name alone reads warmest
        if (preg_match('/^\p{Lu}{3,}$/u', $first)) {
            $first = mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8');   // "ABDI" → "Abdi"
        }

        return $first;
    }

    /**
     * Turn a raw order line ("20x ALUMINIUM FOIL 45CM GOLDEN (CARTON OF 6PC, MIN 5 CARTON)")
     * into something a person would type ("20x Aluminium Foil 45cm Golden"): drop the
     * parenthetical catalogue metadata, soften SHOUTING CAPS, keep it short.
     */
    private function humanItems(string $s): string
    {
        $s = trim(preg_replace('/\s*\([^)]*\)/u', '', $s) ?? '');        // kill (CARTON OF 6PC, MIN 5 CARTON)
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        // Title-case words that are fully SHOUTING (3+ letters); keep short unit tokens as-is.
        $s = preg_replace_callback('/\b\p{Lu}{3,}\b/u', fn ($m) => mb_convert_case(mb_strtolower($m[0]), MB_CASE_TITLE, 'UTF-8'), $s) ?? $s;
        if (mb_strlen($s) > 60) {
            $s = mb_substr($s, 0, 57) . '…';
        }

        return $s;
    }

    /** Fill a message template: {name} {items} {days}. Custom tenant text wins over the default. */
    private function tpl(Tenant $t, string $key, string $default, array $ctx, string $items = '', array $extra = []): string
    {
        $msg = trim((string) $t->setting($key, '')) ?: $default;
        $name = trim((string) ($ctx['name'] ?? '')) ?: 'there';
        $repl = array_merge([
            'name'  => $name,
            'items' => $items !== '' ? $items : (trim((string) ($ctx['interest'] ?? '')) ?: 'your items'),
        ], $extra);
        foreach ($repl as $k => $v) { $msg = str_replace('{' . $k . '}', (string) $v, $msg); }
        // tidy any leftover placeholders
        $msg = preg_replace('/\{[a-z_]+\}/', '', $msg);
        return trim(preg_replace('/\s{2,}/', ' ', $msg));
    }

    private function tenant($id): ?Tenant
    {
        if (! array_key_exists($id, $this->tenantCache)) {
            $this->tenantCache[$id] = Tenant::find($id);
        }
        return $this->tenantCache[$id];
    }
}
