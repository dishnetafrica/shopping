<?php
namespace App\Apps\Orders\Experiment;

use App\Apps\Orders\Dto\EntityBundle;

/**
 * Classifies the disagreement between the LIVE decision (what the current bot put in the cart this
 * turn) and the SHADOW decision (what the new Order Engine extracted). Each field gets a status
 * and a reason code. Product regressions (live had it, shadow missed it, or weight/qty differ) are
 * real disagreements; fields the shadow captured that the live cart model can't represent
 * (address/time/payment) are recorded as improvements, not regressions. Pure & testable.
 */
class DiffClassifier
{
    public const MATCH = 'MATCH';
    public const DIFFER = 'DIFFER';
    public const SHADOW_ONLY = 'SHADOW_ONLY';   // shadow captured something live couldn't (improvement)
    public const LIVE_ONLY = 'LIVE_ONLY';       // live had it, shadow missed it (regression)

    /**
     * @param array<int,array{name:string,weight_grams:?int,quantity:int}> $liveLines items added by the live bot this turn
     * @return array{differs:bool,regressions:bool,items:array<int,array{field:string,status:string,reason:string,detail:string}>}
     */
    public function classify(array $liveLines, EntityBundle $shadow): array
    {
        $items = [];
        $live = $this->index($liveLines);
        $shad = $this->index(array_map(
            fn ($p) => ['name' => $p['name'], 'weight_grams' => $p['weight_grams'] ?? null, 'quantity' => $p['qty'] ?? 1],
            $shadow->products
        ));

        foreach ($live as $key => $l) {
            if (isset($shad[$key])) {
                $items[] = ['field' => 'product:' . $l['name'], 'status' => self::MATCH, 'reason' => '', 'detail' => $this->fmt($l)];
                continue;
            }
            $byName = $this->sameName($shad, $l['name']);
            if ($byName) {
                $reason = $byName['weight_grams'] !== $l['weight_grams'] ? DiffReason::WEIGHT
                        : ($byName['quantity'] !== $l['quantity'] ? DiffReason::QUANTITY : DiffReason::VARIANT);
                $items[] = ['field' => 'product:' . $l['name'], 'status' => self::DIFFER, 'reason' => $reason,
                            'detail' => "live {$this->fmt($l)} vs shadow {$this->fmt($byName)}"];
            } else {
                $items[] = ['field' => 'product:' . $l['name'], 'status' => self::LIVE_ONLY, 'reason' => DiffReason::PRODUCT,
                            'detail' => "live had {$this->fmt($l)}, shadow missed it"];
            }
        }
        foreach ($shad as $key => $s) {
            if (! isset($live[$key]) && ! $this->sameName($live, $s['name'])) {
                $items[] = ['field' => 'product:' . $s['name'], 'status' => self::SHADOW_ONLY, 'reason' => DiffReason::ALIAS,
                            'detail' => "shadow extracted {$this->fmt($s)}, live did not"];
            }
        }

        foreach ([['address', $shadow->address ?? $shadow->deliveryArea, DiffReason::ADDRESS],
                  ['delivery_time', $shadow->deliveryTime, DiffReason::TIME],
                  ['payment_method', $shadow->paymentMethod, DiffReason::PAYMENT]] as [$field, $val, $reason]) {
            if ($val) $items[] = ['field' => $field, 'status' => self::SHADOW_ONLY, 'reason' => $reason, 'detail' => (string) $val];
        }

        $regressions = (bool) array_filter($items, fn ($i) => in_array($i['status'], [self::DIFFER, self::LIVE_ONLY], true));
        $differs = (bool) array_filter($items, fn ($i) => $i['status'] !== self::MATCH);
        return ['differs' => $differs, 'regressions' => $regressions, 'items' => $items];
    }

    private function index(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $name = mb_strtolower(trim((string) ($l['name'] ?? '')));
            if ($name === '') continue;
            $out[$name . '|' . ($l['weight_grams'] ?? 'u')] = ['name' => $name, 'weight_grams' => $l['weight_grams'] ?? null, 'quantity' => (int) ($l['quantity'] ?? 1)];
        }
        return $out;
    }

    private function sameName(array $index, string $name): ?array
    {
        foreach ($index as $row) { if ($row['name'] === mb_strtolower($name)) return $row; }
        return null;
    }

    private function fmt(array $l): string
    {
        return ($l['weight_grams'] ? $l['weight_grams'] . 'g ' : ($l['quantity'] > 1 ? $l['quantity'] . 'x ' : '')) . $l['name'];
    }
}
