<?php

namespace App\Services\Isp;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\MessageLog;
use App\Services\Bot\OrderCalculator;
use App\Services\Bot\QuotationService;
use App\Services\Catalog\Visibility;
use App\Services\Sales\SalesOwnership;
use App\Contracts\WhatsAppGateway;
use App\Support\BotTrace;
use App\Support\QuotationPipeline;

/**
 * The quotation the bot sends an Internet-provider customer from the chat (DishNet, 17 Nov 2026:
 * "send the customer an automatic quotation, Fiber or Starlink, so the customer feels comfortable").
 *
 * The model writes a hidden <<QUOTE {"items":[…]}>> line once the customer has chosen. Here:
 *   1. IspQuotePlan turns the names into visible catalogue products (or says why it can't);
 *   2. OrderCalculator prices them from the catalogue — the model's numbers are never used;
 *   3. QuotationService draws the Internet-provider PDF (one-time and monthly totals apart, terms only
 *      if published) and saves it, prepared by the customer's salesperson;
 *   4. the customer gets the PDF with a short summary, and the salesperson gets an alert to call.
 * When it cannot quote (an unknown product, a large job, no PDF engine), nothing is priced: the
 * customer is told their salesperson will prepare it, and the salesperson is alerted with the request.
 * Switch off with the tenant setting isp_auto_quote = false (the marker is then just removed).
 */
class IspAutoQuote
{
    public const HANDOFF = 'Your salesperson will prepare your quotation and send it to you shortly.';

    public function __construct(private QuotationService $quotes, private OrderCalculator $calc, private SalesOwnership $owners) {}

    public static function enabled(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('isp_auto_quote', true);
    }

    /** @return array{reply:string,sent:bool} reply '' when everything was already sent here */
    public function capture(Tenant $tenant, Conversation $convo, string $from, string $reply, WhatsAppGateway $gateway): array
    {
        $pos = stripos($reply, '<<QUOTE');
        if ($pos === false || ! self::enabled($tenant)) {
            return ['reply' => $reply, 'sent' => false];
        }
        $end = strpos($reply, '>>', $pos);
        $block = $end === false ? substr($reply, $pos) : substr($reply, $pos, $end + 2 - $pos);
        $clean = trim(substr($reply, 0, $pos) . ($end === false ? '' : substr($reply, $end + 2)));
        $name = trim((string) ($convo->customer_name ?? ''));

        try {
            $catalogue = $this->catalogue($tenant);
            $plan = IspQuotePlan::plan(IspQuotePlan::items($block), $catalogue);
            if (! $plan['ok']) {
                $asked = implode(', ', array_map(fn ($i) => $i['qty'] . ' x ' . $i['name'], IspQuotePlan::items($block)));
                QuotationPipeline::stage($tenant->id, $from, 'isp_handoff', false, '', $plan['reason'] . ': ' . mb_substr($asked, 0, 200));

                return ['reply' => $this->handoff($tenant, $from, $name, $clean, "asked for a quotation ({$asked}) — prepare it ({$plan['reason']})"), 'sent' => false];
            }
            if (! $this->quotes->available() || ! method_exists($gateway, 'sendDocument')) {
                QuotationPipeline::stage($tenant->id, $from, 'isp_handoff', false, '', 'no PDF engine / gateway cannot send documents');

                return ['reply' => $this->handoff($tenant, $from, $name, $clean, 'asked for a quotation — the automatic PDF is unavailable, please send it'), 'sent' => false];
            }

            // Priced by the catalogue calculator; every line must be the product the plan chose.
            $quote = $this->calc->quote($tenant, array_map(fn ($l) => ['query' => $l['name'], 'qty' => $l['qty']], $plan['lines']), $from, false);
            $got = array_map(fn ($l) => ! empty($l['matched']) ? (string) $l['name'] : '', $quote['lines']);
            if ($got !== array_column($plan['lines'], 'name')) {
                QuotationPipeline::stage($tenant->id, $from, 'isp_handoff', false, '', 'priced lines differ from the chosen products');

                return ['reply' => $this->handoff($tenant, $from, $name, $clean, 'asked for a quotation — please prepare it'), 'sent' => false];
            }

            if ($again = $this->recent($tenant, $from, $quote)) {
                BotTrace::log($tenant->id, 'isp', $from, 'isp_quote_reused', (string) $again->quote_no);

                return ['reply' => trim($clean . "\n\nThat's your quotation *{$again->quote_no}* from earlier — the same items, still valid \u{1F44D}"), 'sent' => false];
            }

            // The customer's salesperson owns the quotation (prepared-by on the PDF) and is alerted.
            $owner = $this->owners->ownerForEnquiry($tenant, $from, $name !== '' ? $name : null);
            $doc = $this->quotes->generate($tenant, $from, $name, $quote);
            if (! $doc) {
                QuotationPipeline::stage($tenant->id, $from, 'isp_handoff', false, '', 'generate() returned null');

                return ['reply' => $this->handoff($tenant, $from, $name, $clean, 'asked for a quotation — the PDF failed, please send it'), 'sent' => false];
            }
            $row = $this->quotes->persist($tenant, $from, $name, $quote, $doc, 'bot');
            QuotationPipeline::stage($tenant->id, $from, 'isp_quote_sent', true, (string) $doc['no'], $plan['added'] ? 'added: ' . implode(', ', $plan['added']) : '');

            $cur = (string) $doc['currency'];
            $dec = \App\Services\Pricing::decimalsForCurrency($cur);
            $days = ((int) $tenant->setting('quote_validity_days', 14)) ?: 14;
            $caption = self::caption((string) $doc['no'], $cur, (float) $doc['total'], (float) ($doc['monthly_total'] ?? 0), $days, $dec);
            $after = self::afterText($owner?->name, $owner?->phone);

            if ($clean !== '') {
                $gateway->sendText($tenant->whatsapp_instance, $from, $clean);
                MessageLog::record($tenant->id, $from, $tenant->whatsapp_instance, 'out', 'bot', $clean, null, null, ['via' => 'ai']);
            }
            if ($gateway instanceof \App\Services\Bot\CollectorGateway) {
                if ($row) {
                    $gateway->sendDocument('', $from, $row->canonicalPortalUrl(), 'Quotation ' . $doc['no'], '');
                }
            } else {
                $media = (string) (($doc['b64'] ?? '') !== '' ? $doc['b64'] : ($doc['url'] ?? ''));
                $gateway->sendDocument($tenant->whatsapp_instance, $from, $media, (string) $doc['fileName'], $caption);
            }
            MessageLog::record($tenant->id, $from, $tenant->whatsapp_instance, 'out', 'bot', "[quotation {$doc['no']}] " . $caption, null, null, ['via' => 'ai', 'kind' => 'quotation', 'quote_no' => $doc['no']]);

            $this->alert($tenant, $owner, "\u{1F4C4} *Quotation {$doc['no']} sent* by the bot to " . ($name !== '' ? $name . ' ' : '') . "+{$this->digits($from)}\n"
                . implode("\n", array_map(fn ($l) => '• ' . $l['qty'] . ' x ' . $l['name'], $plan['lines'])) . "\n"
                . str_replace("\n", ' • ', $caption) . "\nCall them today: confirm coverage at their location and book the installation.");

            return ['reply' => $after, 'sent' => true];
        } catch (\Throwable $e) {
            report($e);
            // why, in the step log: a failure must be diagnosable, not guessed
            QuotationPipeline::stage($tenant->id, $from, 'isp_failed', false, '', mb_substr(class_basename($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), 0, 250));

            return ['reply' => $this->handoff($tenant, $from, $name, $clean, 'asked for a quotation — the automatic one failed, please send it'), 'sent' => false];
        }
    }

    /** PURE. The line under the PDF: one-time and monthly kept apart, like the PDF itself. */
    public static function caption(string $no, string $cur, float $oneOff, float $monthly, int $days, int $dec = 2): string
    {
        $f = fn (float $v) => $cur . ' ' . number_format($v, $dec);
        $out = "\u{1F4C4} Quotation {$no}";
        if ($oneOff > 0) {
            $out .= "\nOne-time: " . $f($oneOff);
        }
        if ($monthly > 0) {
            $out .= "\nMonthly: " . $f($monthly);
        }

        return $out . "\nValid {$days} days.";
    }

    /** PURE. What the customer reads after the PDF: who will call them, and how to go ahead. */
    public static function afterText(?string $salesName, ?string $salesPhone): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $salesPhone) ?? '';
        $who = trim((string) $salesName) !== ''
            ? '*' . trim((string) $salesName) . '*' . ($digits !== '' ? ' (+' . $digits . ')' : '') . ', your salesperson,'
            : 'Your salesperson';

        return "Here is your quotation \u{1F446}\n\n{$who} will call you to confirm coverage at your exact location and book the installation.\n\nIf you're happy with it, just reply *YES* and we'll take it from there. Any question about it, ask me here.";
    }

    /** Visible, priced products by exact name, with how each is billed and which service it belongs to. */
    private function catalogue(Tenant $tenant): array
    {
        $hidden = Visibility::hiddenCategories($tenant);
        $out = [];
        foreach (Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereNull('archived_at')->where('price', '>', 0)->get() as $p) {
            if (Visibility::isHidden((string) ($p->category ?? ''), $p->hidden ?? null, $hidden)) {
                continue;
            }
            $out[(string) $p->name] = ['billing' => $p->billing, 'service_line' => $p->service_line];
        }

        return $out;
    }

    /** The same quotation already sent to this customer in the last 24 hours, if any. */
    private function recent(Tenant $tenant, string $from, array $quote): ?\App\Models\Quotation
    {
        $want = [];
        foreach ($quote['lines'] as $l) {
            $want[] = mb_strtolower((string) $l['name']) . '|' . (int) $l['qty'];
        }
        sort($want);
        $rows = \App\Models\Quotation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_phone', $from)
            ->whereNotIn('status', ['void', 'declined', 'expired', 'archived'])->where('created_at', '>=', now()->subHours(24))
            ->with('items')->orderByDesc('id')->limit(3)->get();
        foreach ($rows as $q) {
            $have = [];
            foreach ($q->items as $it) {
                $have[] = mb_strtolower((string) $it->name) . '|' . (int) $it->qty;
            }
            sort($have);
            if ($have === $want) {
                return $q;
            }
        }

        return null;
    }

    /** No automatic quotation: tell the customer plainly and give the salesperson the request. */
    private function handoff(Tenant $tenant, string $from, string $name, string $clean, string $what): string
    {
        $owner = null;
        try {
            $owner = $this->owners->ownerForEnquiry($tenant, $from, $name !== '' ? $name : null);
        } catch (\Throwable $e) {
            report($e);
        }
        $this->alert($tenant, $owner, "\u{1F4DD} " . ($name !== '' ? $name . ' ' : '') . "+{$this->digits($from)} {$what}.");

        return trim($clean . "\n\n" . self::HANDOFF);
    }

    private function alert(Tenant $tenant, ?\App\Models\User $owner, string $text): void
    {
        try {
            $to = $owner && $this->digits((string) $owner->phone) !== '' ? [$this->digits((string) $owner->phone)] : $tenant->ownerAlertNumbers();
            foreach ($to as $n) {
                \App\Jobs\NotifyOwner::dispatch($tenant->id, $text, (string) $n);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function digits(string $s): string
    {
        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }
}
