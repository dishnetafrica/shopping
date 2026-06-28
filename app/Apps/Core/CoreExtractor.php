<?php
namespace App\Apps\Core;

use App\Services\Knowledge\Contracts\Extractor;
use App\Services\Knowledge\Dto\ActionRequest;
use App\Services\Knowledge\Dto\ExtractionResult;
use App\Services\Knowledge\Dto\Fact;
use App\Services\Knowledge\EntityConfidence;
use App\Services\Knowledge\Intent;

/**
 * Core (cross-industry) extraction: prices, durable policies/facilities, simple schedule toggles.
 * Pure & deterministic. Prices emit BOTH a Fact (audit/memory) and a gated Action (live update);
 * durable policies/facilities emit Facts; today-scoped changes emit operational Actions.
 */
class CoreExtractor implements Extractor
{
    public function extract(string $text, array $profile = []): ExtractionResult
    {
        $r = new ExtractionResult(intent: Intent::PRICE);
        $low = mb_strtolower($text);

        // free delivery above N  (durable policy)
        if (preg_match('/free delivery (?:above|over|from)\s+([0-9][0-9,\.]*\s*k?)/i', $text, $m)) {
            $r->facts[] = new Fact('core', 'Policy', 'delivery:free_threshold', ['threshold' => $this->num($m[1])], 0.95);
        }
        // minimum order N (durable policy)
        if (preg_match('/min(?:imum)? order\s+([0-9][0-9,\.]*\s*k?)/i', $text, $m)) {
            $r->facts[] = new Fact('core', 'Policy', 'order:minimum', ['minimum' => $this->num($m[1])], 0.95);
        }
        // facilities (durable)
        foreach (['parking' => 'parking', 'wifi' => 'wifi', 'wi-fi' => 'wifi', 'seating' => 'seating'] as $kw => $key) {
            if (str_contains($low, $kw)) {
                $r->facts[] = new Fact('core', 'Facility', $key, ['text' => ucfirst($key) . ' available'], 0.9);
            }
        }
        // cash only today (operational, gated)
        if (str_contains($low, 'cash only')) {
            $r->actions[] = new ActionRequest('core', 'set_operational', 'payment',
                ['key' => 'payment', 'value' => ['text' => 'Cash only today', 'methods' => ['cash']], 'scope' => 'today']);
            $r->reason = \App\Services\Knowledge\Reason::SUPPLIER_ISSUE;
        }
        // closed today/tomorrow (operational schedule, gated)
        if (preg_match('/closed\s+(today|tomorrow)/i', $low, $m)) {
            $date = strtolower($m[1]) === 'tomorrow' ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
            $r->actions[] = new ActionRequest('core', 'set_operational', 'hours',
                ['key' => 'hours', 'value' => ['closed' => true], 'scope' => 'dated', 'date' => $date]);
            $r->facts[] = new Fact('core', 'Schedule', 'closure:' . $date, ['closed' => true], 0.95, 'dated', $date);
        }

        // bare price lines: "Tea 5000", "Tea 5k", "Masala Chai 4,000"
        foreach (preg_split('/[\n;]+/', $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^([a-z][a-z0-9 &\'\-\.]+?)\s+(?:ugx\s*)?([0-9][0-9,\.]*\s*k?)$/i', $line, $m)) {
                $name = $this->cleanName($m[1]);
                $price = $this->num($m[2]);
                if ($name === '' || $price <= 0) continue;
                $entities = [
                    EntityConfidence::entity('product', $name, 0.96),
                    EntityConfidence::entity('price', $price, 0.97),
                ];
                $r->actions[] = new ActionRequest('core', 'set_price', $name, ['price' => $price], $entities);
                $r->facts[]   = new Fact('core', 'Price', $this->slug($name) . ':price', ['price' => $price], 0.96);
            }
        }

        if (! $r->actions && ! $r->facts) $r->intent = Intent::NOTE;
        return $r;
    }

    private function num(string $s): int
    {
        $s = strtolower(trim($s));
        $k = str_ends_with($s, 'k');
        $n = (float) str_replace([',', ' ', 'k'], '', $s);
        return (int) round($k ? $n * 1000 : $n);
    }

    private function cleanName(string $s): string { return ucwords(trim(preg_replace('/\s+/', ' ', mb_strtolower($s)) ?? '')); }
    private function slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($s)) ?? '', '-'); }
}
