<?php
namespace App\Apps\Orders\Extract;

use App\Apps\Orders\Dto\EntityBundle;
use App\Services\Bot\LocationDictionary;
use App\Services\Bot\ProductAlias;
use App\Services\Bot\ShoppingParser;

/**
 * Returns ALL entities from one message — never throws information away. Address/time/payment/
 * phone/area are detected and STRIPPED first (so "Ntinda" / "8 AM" never reach the product
 * matcher), then the remainder is parsed for products (reusing the existing ShoppingParser).
 * Pure (deterministic); product name→id and price are resolved later by a ProductResolver.
 */
class EntityExtractor
{
    public function __construct(private ?ShoppingParser $parser = null)
    {
        $this->parser ??= new ShoppingParser();
    }

    public function extract(string $text): EntityBundle
    {
        $work = ' ' . $text . ' ';
        $conf = [];

        $addr = AddressRecognizer::detect($text);
        $work = $this->strip($work, $addr['spans']);
        if ($addr['address']) $conf['address'] = $addr['confidence'];
        if ($addr['landmark']) $conf['landmark'] = max($conf['landmark'] ?? 0, 0.7);

        $time = TimeParser::detect($work);
        if ($time['span']) $work = $this->strip($work, [$time['span']]);
        if ($time['time']) $conf['delivery_time'] = $time['confidence'];

        $pay = PaymentDetector::detect($work);
        if ($pay['span']) $work = $this->strip($work, [$pay['span']]);
        if ($pay['method']) $conf['payment_method'] = $pay['confidence'];

        $phone = PhoneDetector::detect($work);
        if ($phone['span']) $work = $this->strip($work, [$phone['span']]);
        if ($phone['phone']) $conf['phone'] = $phone['confidence'];

        [$area, $areaSpan] = $this->matchArea($work);
        if ($area && ! $addr['address']) { $work = $this->strip($work, [$areaSpan]); $conf['delivery_area'] = 0.95; }

        $products = [];
        $work = $this->stripNegations($work);                                                     // "no jalebi" must not become a line
        $work = preg_replace('/\b(send|deliver|bring|drop|kindly)\b/i', ' ', $work) ?? $work;  // order verbs aren't products
        $parsed = $this->parser->parse(trim($work));
        foreach (($parsed['items'] ?? []) as $it) {
            $q = trim((string) ($it['query'] ?? ''));
            if ($q === '') continue;
            $name = ProductAlias::canonical($q) ?? $this->title($q);
            $grams = $this->toGrams($it['size'] ?? null);
            $products[] = [
                'name' => $name,
                'weight_grams' => $grams,
                'qty' => $grams ? (int) ($it['count'] ?? 1) : (int) ($it['count'] ?? $it['qty'] ?? 1),
                'confidence' => 0.9,
            ];
        }
        if ($products) $conf['lines'] = 0.9;

        return new EntityBundle(
            products: $products,
            deliveryArea: $area,
            address: $addr['address'] ?? $addr['maps'],
            landmark: $addr['landmark'],
            deliveryTime: $time['time'],
            deliveryDate: $time['date'],
            paymentMethod: $pay['method'],
            phone: $phone['phone'],
            confidence: $conf,
        );
    }

    /**
     * Remove explicitly excluded items so they never become product lines: "no jalebi",
     * "without sev", "skip the samosa". Scoped to a single line (no newline in the trailing words)
     * so it can't swallow the next item in a multi-line order.
     */
    private function stripNegations(string $work): string
    {
        return preg_replace(
            '/\b(?:no|without|skip)[ \t]+(?:the[ \t]+)?[a-z]+(?:[ \t]+[a-z]+)?/i',
            ' ',
            $work
        ) ?? $work;
    }

    private function strip(string $work, array $spans): string
    {
        foreach ($spans as $s) { if ($s) $work = str_ireplace($s, ' ', $work); }
        return $work;
    }

    /** @return array{0:?string,1:?string} canonical area, matched span */
    private function matchArea(string $work): array
    {
        $w = ' ' . mb_strtolower($work) . ' ';
        $candidates = [];
        foreach (array_merge(LocationDictionary::KAMPALA, LocationDictionary::JUBA) as $name) $candidates[mb_strtolower($name)] = $name;
        foreach (LocationDictionary::ALIASES as $alias => $canon) $candidates[mb_strtolower($alias)] = $canon;
        uksort($candidates, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // longest first
        foreach ($candidates as $needle => $canon) {
            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $w)) return [$canon, $needle];
        }
        return [null, null];
    }

    private function toGrams(?string $size): ?int
    {
        if (! $size) return null;
        $s = mb_strtolower($size);
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|kgs)\b/', $s, $m)) return (int) round(((float) $m[1]) * 1000);
        if (preg_match('/(\d+(?:\.\d+)?)\s*(g|gm|gms|gram|grams)\b/', $s, $m)) return (int) round((float) $m[1]);
        return null;
    }

    private function title(string $s): string { return ucwords(trim(preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? '')); }
}
