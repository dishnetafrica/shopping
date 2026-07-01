<?php
namespace App\Services\Bot\Pricing;

use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * One place that knows how a UGX price becomes a USD / export price. Both the bot and the panel use
 * this, so the formula can never drift between them.
 *
 *   USD unit = base_UGX × (1 + ssMarkupPct/100) ÷ usdUgx,  rounded to the nearest $0.50
 *
 * The markup is the South Sudan / Juba markup, with a per-client override on
 * customer_profiles.ss_markup_pct. For a plain USD request (no SS) the markup is simply 0.
 */
class CurrencyPricer
{
    public function usdRate(Tenant $t): float
    {
        $rate = (float) $t->setting('usdUgx', 3750);
        return $rate > 0 ? $rate : 3750.0;
    }

    /**
     * True when the tenant's prices ARE their currency (no UGX base, no Uganda↔South-Sudan
     * conversion) — e.g. a South Sudan grocer whose catalogue is native USD, or a Rwanda shop in
     * RWF. Mirrors PanelCurrency's "plain" flag so the bot and panel agree: native when the owner
     * set currency_basis=native, or the currency isn't part of the convertible UGX/SSP/USD model.
     * For such tenants we NEVER convert — dividing a native $33 price by the UGX rate would wreck it.
     */
    public function isNativeBasis(Tenant $t): bool
    {
        $raw = strtoupper(trim((string) $t->setting('currency', 'UGX')));
        if ($raw === '' || $raw === 'AUTO') $raw = 'UGX';
        if (strtolower(trim((string) $t->setting('currency_basis', ''))) === 'native') return true;
        return ! in_array($raw, ['AUTO', 'UGX', 'SSP', 'USD'], true);
    }

    /** Per-client SS markup % (customer override) else the tenant default; 0 when neither is set. */
    public function ssMarkupFor(Tenant $t, string $phone): float
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits !== '') {
            $c = CustomerProfile::where('tenant_id', $t->id)->where('phone', $digits)->first();
            if ($c && $c->ss_markup_pct !== null) return (float) $c->ss_markup_pct;
        }
        return (float) $t->setting('ssMarkupPct', 0);
    }

    /** Convert a UGX quote to USD in place (matched lines only). Returns ['quote'=>…, 'meta'=>…]. */
    public function toUsd(Tenant $t, string $phone, array $quote): array
    {
        // Safety net: never convert a native-currency quote (prices already ARE the currency).
        if ($this->isNativeBasis($t)) {
            return ['quote' => $quote, 'meta' => ['native' => true]];
        }
        $rate   = $this->usdRate($t);
        $pct    = $this->ssMarkupFor($t, $phone);
        $factor = (1 + $pct / 100) / $rate;
        $total  = 0.0;
        foreach ($quote['lines'] as &$ln) {
            if (empty($ln['matched'])) continue;
            $unit        = round(((float) $ln['price']) * $factor * 2) / 2;   // nearest $0.50
            $ln['price'] = $unit;
            $ln['sum']   = $unit * (int) $ln['qty'];
            $total      += $ln['sum'];
        }
        unset($ln);
        $quote['total']    = round($total, 2);
        $quote['currency'] = 'USD';
        return ['quote' => $quote, 'meta' => ['usd_rate' => $rate, 'markup_pct' => $pct]];
    }

    public function wantsUsd(string $text): bool
    {
        return (bool) preg_match('/\b(usd|us\$|dollars?|export)\b|\$\s*\d|in \$/i', $text);
    }

    public function mentionsSouthSudan(string $text): bool
    {
        return (bool) preg_match('/\b(juba|south\s*sudan|s\.?\s*sudan|southsudan|ssd)\b/i', $text);
    }

    /** Current message + customer address + recent inbound messages, so intent carries across turns. */
    public function context(Tenant $t, string $phone, string $currentText): string
    {
        $bits   = [$currentText];
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits !== '') {
            $c = CustomerProfile::where('tenant_id', $t->id)->where('phone', $digits)->first();
            if ($c && $c->address) $bits[] = (string) $c->address;
        }
        try {
            $recent = Message::where('tenant_id', $t->id)->where('customer_phone', $phone)
                ->where('direction', 'in')->latest()->limit(8)->pluck('body')->all();
            $bits = array_merge($bits, $recent);
        } catch (\Throwable $e) { /* best-effort */ }
        return implode(' ', array_filter($bits));
    }

    public function isSouthSudan(Tenant $t, string $phone, string $currentText): bool
    {
        return $this->mentionsSouthSudan($this->context($t, $phone, $currentText))
            || Str::startsWith(preg_replace('/[^0-9]/', '', $phone), '211');
    }

    /** Quote in USD when the rate exists and the customer wants USD / export, or ships to South Sudan. */
    public function shouldQuoteUsd(Tenant $t, string $phone, string $currentText): bool
    {
        // Native-currency tenants (prices ARE the currency) are never converted — a native USD
        // catalogue is already USD, and a native RWF one has no USD rate to convert with.
        if ($this->isNativeBasis($t)) return false;
        if ($this->usdRate($t) <= 0) return false;
        $ctx = $this->context($t, $phone, $currentText);
        return $this->wantsUsd($ctx) || $this->mentionsSouthSudan($ctx)
            || Str::startsWith(preg_replace('/[^0-9]/', '', $phone), '211');
    }

    /** Stable signature of a quote's matched lines + currency, for de-duplicating repeat quotes. */
    public function signature(array $quote): string
    {
        $items = [];
        foreach ($quote['lines'] ?? [] as $l) {
            if (empty($l['matched'])) continue;
            $items[] = Str::lower((string) ($l['name'] ?? '')) . '|' . (int) ($l['qty'] ?? 0) . '|' . (float) ($l['price'] ?? 0);
        }
        sort($items);
        return md5(((string) ($quote['currency'] ?? 'UGX')) . ';' . implode(';', $items));
    }
}
