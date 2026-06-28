<?php
namespace App\Apps\Orders\Extract;

/**
 * Recognises delivery location BEFORE the product matcher so "503 Silver Condos" never becomes a
 * product search. Detects apartments/condos, roads, landmarks, Google Maps links, pins and coords.
 * Pure. Returns matched spans so the caller can strip them before product parsing.
 */
class AddressRecognizer
{
    /** @return array{address:?string,landmark:?string,maps:?string,spans:string[],confidence:float} */
    public static function detect(string $text): array
    {
        $address = null; $landmark = null; $maps = null; $spans = []; $conf = 0.0;

        if (preg_match('#https?://(?:maps\.app\.goo\.gl|goo\.gl/maps|(?:www\.)?google\.[a-z.]+/maps)\S*#i', $text, $m)) {
            $maps = $m[0]; $spans[] = $m[0]; $conf = max($conf, 0.99);
        }
        if (preg_match('/-?\d{1,2}\.\d{3,}\s*,\s*-?\d{1,3}\.\d{3,}/', $text, $m)) {
            $maps = $maps ?? $m[0]; $spans[] = $m[0]; $conf = max($conf, 0.99);
        }
        // unit / condo / apartment forms, incl. "503 silver condos", "flat 2B"
        if (preg_match('/\b(?:flat|apartment|apt|unit|house|plot|room|block|door)\s*#?\s*[\w-]+/i', $text, $m)) {
            $address = trim($m[0]); $spans[] = $m[0]; $conf = max($conf, 0.9);
        }
        if (preg_match('/\b\d+\s+[a-z][a-z ]*?\b(condos?|apartments?|towers?|court|estate|villas?|flats?|building|residenc(?:y|es?)|plaza|heights)\b/i', $text, $m)) {
            $address = $address ?? trim($m[0]); $spans[] = $m[0]; $conf = max($conf, 0.9);
        }
        if (preg_match('/\b[a-z][a-z ]*?\b(road|street|avenue|lane|close|drive|highway|rd|ave|cresc(?:ent)?)\b/i', $text, $m)) {
            $address = $address ?? trim($m[0]); $spans[] = $m[0]; $conf = max($conf, 0.8);
        }
        if (preg_match('/\b(near|opposite|behind|next to|beside|by|in front of)\s+([a-z0-9][a-z0-9 \'\-]{2,})/i', $text, $m)) {
            $landmark = trim($m[0]); $spans[] = $m[0]; $conf = max($conf, 0.7);
        }

        return ['address' => $address, 'landmark' => $landmark, 'maps' => $maps, 'spans' => $spans, 'confidence' => $conf];
    }
}
