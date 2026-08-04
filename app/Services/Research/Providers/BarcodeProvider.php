<?php

namespace App\Services\Research\Providers;

use App\Services\Research\Contracts\ResearchProvider;
use App\Services\Research\Dto\Finding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Open Food Facts, by barcode.
 *
 * This is the only source in the pipeline that is FACT rather than inference: the
 * EAN on the bottle identifies exactly one product, so brand / size / origin come
 * back as recorded data, not as a model's recollection. That is why its findings
 * carry 0.95 and the AI provider's carry far less.
 *
 * Terms we are honouring:
 *   - a descriptive User-Agent with a contact, as they ask for;
 *   - "1 API call = 1 real scan by a user" — this class is called once per scan on
 *     the panel, and the bulk command only reaches it behind an explicit flag with
 *     a throttle and a per-run cap;
 *   - the data is ODbL, so attribution is rendered wherever these facts surface.
 *
 * Never throws. A miss, a timeout and a 404 are all "[]" — an unknown barcode is
 * the normal case for local repacks and no-name goods, not an error.
 */
final class BarcodeProvider implements ResearchProvider
{
    /** A barcode lookup is a database hit on an exact key, not a guess. */
    private const CONF_EXACT = 0.95;

    /** The name Open Food Facts holds is a label transcription — good, but not our house style. */
    private const CONF_NAME = 0.70;

    public function __construct(
        private string $base = '',
        private int $timeout = 12,
        private string $userAgent = '',
        private int $cacheDays = 30,
    ) {
        $this->base = rtrim($this->base ?: (string) config('research.barcode.base'), '/');
        $this->timeout = $this->timeout ?: (int) config('research.barcode.timeout', 12);
        $this->userAgent = $this->userAgent ?: (string) config('research.barcode.user_agent');
        $this->cacheDays = $this->cacheDays ?: (int) config('research.barcode.cache_days', 30);
    }

    public function key(): string
    {
        return 'barcode';
    }

    public function isEnabled(): bool
    {
        return $this->base !== '';
    }

    /** A usable EAN/UPC: 8, 12, 13 or 14 digits. Anything else is an internal shelf code. */
    public static function normalise(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';

        return in_array(strlen($d), [8, 12, 13, 14], true) ? $d : '';
    }

    public function research(array $product, array $wanted): array
    {
        $ean = self::normalise((string) ($product['barcode'] ?? ''));
        if ($ean === '' || ! $this->isEnabled()) {
            return [];
        }

        $raw = $this->fetch($ean);
        if (! is_array($raw)) {
            return [];
        }

        return self::parse($raw, $ean);
    }

    /** Cached HTTP. Returns the `product` object, or null for miss/failure. */
    public function fetch(string $ean): ?array
    {
        $ck = 'off:v2:' . $ean;

        return Cache::remember($ck, now()->addDays(max(1, $this->cacheDays)), function () use ($ean) {
            try {
                $fields = 'product_name,brands,quantity,product_quantity,product_quantity_unit,'
                    . 'countries,countries_tags,origins,manufacturing_places,categories,'
                    . 'generic_name,ingredients_text,image_front_url,image_url';
                $resp = Http::withHeaders(['User-Agent' => $this->userAgent, 'Accept' => 'application/json'])
                    ->timeout($this->timeout)
                    ->get($this->base . '/api/v2/product/' . $ean . '.json', ['fields' => $fields]);

                if (! $resp->ok()) {
                    return null;
                }
                $j = $resp->json();
                if (! is_array($j) || (int) ($j['status'] ?? 0) !== 1 || ! is_array($j['product'] ?? null)) {
                    return null;                                   // status 0 = barcode not in the database
                }

                return $j['product'];
            } catch (\Throwable $e) {
                return null;                                       // never throws — see the class docblock
            }
        });
    }

    // ------------------------------------------------------------ pure parsing (unit-tested)

    /**
     * Map an Open Food Facts product object onto Findings.
     * Static and side-effect free so the mapping can be tested against real payloads
     * without a network — the HTTP call above is the only untestable part.
     *
     * @return Finding[]
     */
    public static function parse(array $p, string $ean = ''): array
    {
        $out = [];
        $note = 'Open Food Facts · barcode ' . ($ean !== '' ? $ean : '?');

        $brand = self::firstOf((string) ($p['brands'] ?? ''));
        if ($brand !== '') {
            $out[] = new Finding('brand', $brand, self::CONF_EXACT, 'barcode', $note);
        }

        $size = self::size($p);
        if ($size !== '') {
            $out[] = new Finding('unit_size', $size, self::CONF_EXACT, 'barcode', $note);
        }

        $origin = self::origin($p);
        if ($origin !== '') {
            // "countries" on OFF is where the product is SOLD, which is only weak evidence of
            // where it is made. `origins`/`manufacturing_places` are the real thing, so origin()
            // returns them with a marker and we downgrade the sold-in fallback accordingly.
            [$val, $exact] = $origin;
            $out[] = new Finding(
                'origin_country', $val,
                $exact ? self::CONF_EXACT : 0.60,
                'barcode',
                $exact ? $note : $note . ' (listed as sold in this country — confirm it is where it is made)',
            );
        }

        $cat = self::firstOf((string) ($p['categories'] ?? ''));
        if ($cat !== '') {
            $out[] = new Finding('category', self::titleish($cat), 0.62, 'barcode',
                $note . ' — their category wording, not yours');
        }

        $name = trim((string) ($p['product_name'] ?? ''));
        if ($name !== '') {
            $out[] = new Finding('name_hint', $name, self::CONF_NAME, 'barcode', $note);
        }

        $img = trim((string) ($p['image_front_url'] ?? $p['image_url'] ?? ''));
        if ($img !== '' && preg_match('#^https://[a-z0-9.\-]*openfoodfacts\.org/#i', $img)) {
            $out[] = new Finding('image_hint', $img, self::CONF_EXACT, 'barcode', $note);
        }

        return $out;
    }

    /** OFF stores comma-separated lists in single fields; take the first, tidily. */
    private static function firstOf(string $csv): string
    {
        foreach (explode(',', $csv) as $bit) {
            $bit = trim($bit);
            if ($bit !== '') {
                return mb_substr($bit, 0, 60);
            }
        }

        return '';
    }

    /** "500 ml" / "500ml" / product_quantity+unit → our compact "500ml". */
    private static function size(array $p): string
    {
        $q = trim((string) ($p['quantity'] ?? ''));
        if ($q !== '') {
            $n = self::compactSize($q);
            if ($n !== '') {
                return $n;
            }
        }
        $num = $p['product_quantity'] ?? null;
        $unit = trim((string) ($p['product_quantity_unit'] ?? ''));
        if (is_numeric($num) && $unit !== '') {
            return self::compactSize(((float) $num == (int) $num ? (string) (int) $num : (string) $num) . $unit);
        }

        return '';
    }

    /** Normalise a free-text quantity to "<number><unit>", or '' if it is not one. */
    public static function compactSize(string $raw): string
    {
        $s = mb_strtolower(trim($raw));
        $s = str_replace([' ', ' '], ' ', $s);
        if (! preg_match('/(\d+(?:[.,]\d+)?)\s*(ml|cl|dl|l|ltr|litre|liter|g|gm|gr|gram|grams|kg|mg|oz|lb|pcs|pc|piece|pieces)\b/u', $s, $m)) {
            return '';
        }
        // Trailing zeros are only noise AFTER a decimal point — "1.50"→"1.5" but "500" stays 500.
        // (Stripping unconditionally turned every 500ml bottle into a 5ml one.)
        $n = str_replace(',', '.', $m[1]);
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }
        $n = $n === '' ? '0' : $n;
        $unit = [
            'litre' => 'L', 'liter' => 'L', 'ltr' => 'L', 'l' => 'L',
            'gram' => 'g', 'grams' => 'g', 'gm' => 'g', 'gr' => 'g',
            'piece' => 'pcs', 'pieces' => 'pcs', 'pc' => 'pcs',
        ][$m[2]] ?? $m[2];

        return $n . $unit;
    }

    /**
     * Where it is MADE, preferred over where it is sold.
     *
     * @return array{0:string,1:bool}|string  [country, isRealOrigin] or '' when unknown
     */
    private static function origin(array $p)
    {
        foreach (['origins', 'manufacturing_places'] as $f) {
            $v = self::firstOf((string) ($p[$f] ?? ''));
            if ($v !== '') {
                return [self::titleish($v), true];
            }
        }
        $tags = $p['countries_tags'] ?? null;
        if (is_array($tags) && count($tags) === 1) {           // exactly one → a usable hint
            $v = preg_replace('/^[a-z]{2}:/', '', (string) $tags[0]);
            $v = self::titleish(str_replace('-', ' ', (string) $v));
            if ($v !== '') {
                return [$v, false];
            }
        }

        return '';
    }

    private static function titleish(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');

        return mb_substr($s === '' ? '' : (mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1)), 0, 60);
    }
}
