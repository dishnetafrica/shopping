<?php
namespace App\Services\Bot\Opportunity;

/**
 * Pulls structured facts out of a free-text WhatsApp message into opportunity profile
 * slots. Deterministic and free (no AI) — mirrors the IntentRouter philosophy. Each
 * call returns only the slots it is confident about; the engine merges them in.
 *
 * Designed to slot-fill from messy real answers, e.g. "I have a shop on Ttula Road,
 * I take 200 cartons of another brand at 8500" fills business hint + area + volume +
 * reseller signal + price in one turn.
 */
final class SlotExtractor
{
    /** @return array<string,mixed> partial profile (only confident keys) */
    public function extract(string $text, array $profile, ?string $expectingSlot = null): array
    {
        $raw = trim($text);
        $lc  = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', $raw)) . ' ';
        $out = [];

        // ---- price (UGX/SSP amounts, e.g. "8500", "8,500", "ugx 8500", "@ 8.5k") ----
        $price = $this->money($lc);
        if ($price !== null && empty($profile['buying_price'])) {
            $out['buying_price'] = $price;
        }

        // ---- monthly volume in cartons / units ----
        if (empty($profile['monthly_volume'])) {
            $vol = $this->volume($lc);
            if ($vol !== null) $out['monthly_volume'] = $vol;
        }

        // ---- order frequency ----
        if (empty($profile['order_frequency'])) {
            if (str_contains($lc, 'weekly') || str_contains($lc, 'every week') || str_contains($lc, 'per week')) $out['order_frequency'] = 'weekly';
            elseif (str_contains($lc, 'twice a month') || str_contains($lc, 'fortnight') || str_contains($lc, 'every two week')) $out['order_frequency'] = 'twice_monthly';
            elseif (str_contains($lc, 'monthly') || str_contains($lc, 'every month') || str_contains($lc, 'per month') || str_contains($lc, 'once a month')) $out['order_frequency'] = 'monthly';
            elseif (str_contains($lc, 'daily') || str_contains($lc, 'every day')) $out['order_frequency'] = 'daily';
        }

        // ---- trade level ----
        if (empty($profile['trade_level'])) {
            if (str_contains($lc, 'wholesale') || str_contains($lc, 'distribut') || str_contains($lc, 'supply')) $out['trade_level'] = 'wholesale';
            elseif (str_contains($lc, 'retail') || str_contains($lc, ' shop ') || str_contains($lc, 'kiosk') || str_contains($lc, 'duka')) $out['trade_level'] = 'retail';
        }

        // ---- existing reseller signal (drives scoring) ----
        if (empty($profile['existing_reseller'])) {
            foreach (['already buy', 'already take', 'already sell', 'currently buy', 'currently take', 'i take another', 'another brand', 'other brand', 'i stock'] as $p) {
                if (str_contains($lc, $p)) { $out['existing_reseller'] = true; break; }
            }
        }

        // ---- has retail outlet signal ----
        if (empty($profile['has_outlet'])) {
            foreach (['i have a shop', 'my shop', 'my store', 'our shop', 'supermarket', 'pharmacy', 'wholesaler', 'i have a duka', 'my duka', 'outlet'] as $p) {
                if (str_contains($lc, $p)) { $out['has_outlet'] = true; break; }
            }
        }

        // ---- email ----
        if (empty($profile['contact_email']) && preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $raw, $m)) {
            $out['contact_email'] = $m[0];
        }

        // ---- direct-answer slot capture ----
        // When we explicitly asked for a slot, treat a short free-text reply as the answer
        // (unless we already parsed it structurally above).
        if ($expectingSlot && ! array_key_exists($expectingSlot, $out) && empty($profile[$expectingSlot])) {
            $val = $this->shortAnswer($raw);
            if ($val !== null) {
                if ($expectingSlot === 'interested_products') {
                    $out['interested_products'] = $val;
                } elseif (in_array($expectingSlot, ['business_name', 'area', 'current_brand', 'decision_maker'], true)) {
                    $out[$expectingSlot] = $val;
                }
            }
        }

        return $out;
    }

    /** Largest plausible money amount in the text, or null. Handles 8,500 / 8500 / 8.5k. */
    private function money(string $lc): ?int
    {
        $best = null;
        if (preg_match_all('/(?<![\d.])(\d{1,3}(?:[,\s]\d{3})+|\d{3,7})(?![\d.])/', $lc, $m)) {
            foreach ($m[1] as $n) {
                $v = (int) preg_replace('/[,\s]/', '', $n);
                if ($v >= 500 && $v <= 5_000_000) $best = max((int) $best, $v);
            }
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*k\b/', $lc, $m)) {
            $v = (int) round(((float) $m[1]) * 1000);
            if ($v >= 500) $best = max((int) $best, $v);
        }
        return $best;
    }

    /** Monthly carton/unit volume, or null. Prefers an explicit "X cartons". */
    private function volume(string $lc): ?int
    {
        if (preg_match('/(\d{1,6})\s*(cartons?|boxes?|cases?|bales?|units?|pcs|pieces|bags?)/', $lc, $m)) {
            return (int) $m[1];
        }
        // "200 per month" / "around 300 monthly"
        if (preg_match('/(\d{1,6})\s*(?:\/|per\s*)?(?:month|monthly|a month|pm)\b/', $lc, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /** A concise free-text answer, trimmed; null if it looks like noise / too long. */
    private function shortAnswer(string $raw): ?string
    {
        $s = trim($raw);
        if ($s === '') return null;
        if (mb_strlen($s) > 80) return null;            // probably a sentence, not a slot value
        if (preg_match('/^\d+$/', $s)) return null;      // a bare number is handled by structural parsers
        return $s;
    }
}
