<?php

namespace App\Services\Sales\Brain;

use App\Services\Delivery\ZoneResolver;

/**
 * PURE. "Can we serve this customer?" from the PUBLISHED coverage areas only.
 *
 * The answer is one of: covered, survey (a site survey is needed), none (we do not serve there).
 * When no published area matches the place, the answer is ALWAYS "survey" — the bot and the coach
 * never tell a customer they are covered unless management has published that area as covered.
 * Matching reuses the delivery-zone matcher (place name in the text, else a pin inside a circle).
 */
final class CoverageCheck
{
    /**
     * @param array  $areas        published coverage areas (Knowledge::validate shape), any service
     * @param string $serviceLine  starlink | fiber
     * @return array{status:string, area:?string, note:string, matched:bool}
     */
    public static function check(array $areas, string $serviceLine, string $placeText, ?float $lat = null, ?float $lng = null): array
    {
        $zones = [];
        foreach ($areas as $a) {
            if (($a['service_line'] ?? null) !== $serviceLine || ! in_array($a['status'] ?? '', Knowledge::COVERAGE, true)) {
                continue;
            }
            $zones[] = ['area' => $a, 'match_keywords' => $a['keywords'] ?? [], 'center_lat' => $a['lat'] ?? null,
                'center_lng' => $a['lng'] ?? null, 'radius_m' => $a['radius_m'] ?? null];
        }
        // Place names: the MOST SPECIFIC (longest) name found in the text wins, so "Test Town Hills —
        // not served" beats "Test Town — covered"; on a tie the more cautious answer wins.
        $text = mb_strtolower(trim($placeText));
        $hit = null; $hitLen = 0;
        $caution = ['none' => 3, 'survey' => 2, 'covered' => 1];
        if ($text !== '') {
            foreach ($zones as $z) {
                foreach ((array) $z['match_keywords'] as $kw) {
                    $len = mb_strlen($kw);
                    if ($kw === '' || ! str_contains($text, $kw)) {
                        continue;
                    }
                    if ($len > $hitLen || ($len === $hitLen && $caution[$z['area']['status']] > $caution[$hit['area']['status']])) {
                        $hit = $z; $hitLen = $len;
                    }
                }
            }
        }
        // Else a pin inside a published circle (the delivery-zone matcher: closest centre wins).
        if (! $hit && $zones && $lat !== null && $lng !== null) {
            $hit = ZoneResolver::matchZone('', $lat, $lng, $zones);
        }
        if (! $hit) {
            return ['status' => 'survey', 'area' => null, 'note' => '', 'matched' => false];
        }

        return ['status' => $hit['area']['status'], 'area' => $hit['area']['name'], 'note' => (string) ($hit['area']['note'] ?? ''), 'matched' => true];
    }
}
