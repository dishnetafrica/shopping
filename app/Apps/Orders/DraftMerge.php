<?php
namespace App\Apps\Orders;

use App\Apps\Orders\Dto\CartLine;
use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\Dto\EntityBundle;

/**
 * Applies a message's extracted entities onto the persistent draft and returns the timeline
 * events that describe the change. Fields are only overwritten when the new value is present and
 * at least as confident; product lines accumulate (same product+weight replaces, keeping history).
 */
class DraftMerge
{
    /** @return array{0:DraftOrder,1:array<int,array{field:string,value:mixed}>} updated draft, events */
    public function apply(DraftOrder $draft, EntityBundle $b): array
    {
        $events = [];

        foreach ($b->products as $p) {
            $line = new CartLine(
                name: $p['name'], weightGrams: $p['weight_grams'] ?? null,
                quantity: max(1, (int) ($p['qty'] ?? 1)), confidence: (float) ($p['confidence'] ?? 1.0),
            );
            $replaced = false;
            foreach ($draft->lines as $i => $existing) {
                if ($existing->key() === $line->key()) { $draft->lines[$i] = $line; $replaced = true; break; }
            }
            if (! $replaced) $draft->lines[] = $line;
            $events[] = ['field' => 'line', 'value' => $line->toArray()];
        }
        if ($b->products) $draft->confidence['lines'] = max($draft->confidence['lines'] ?? 0, $b->confidence['lines'] ?? 0.9);

        $this->set($draft, $events, 'address', $b->address, $b->confidence['address'] ?? 0);
        $this->set($draft, $events, 'landmark', $b->landmark, $b->confidence['landmark'] ?? 0);
        $this->set($draft, $events, 'delivery_area', $b->deliveryArea, $b->confidence['delivery_area'] ?? 0);
        $this->set($draft, $events, 'delivery_time', $b->deliveryTime, $b->confidence['delivery_time'] ?? 0);
        $this->set($draft, $events, 'delivery_date', $b->deliveryDate, 0.8);
        $this->set($draft, $events, 'payment_method', $b->paymentMethod, $b->confidence['payment_method'] ?? 0);
        $this->set($draft, $events, 'phone', $b->phone, $b->confidence['phone'] ?? 0);
        $this->set($draft, $events, 'notes', $b->notes, 0.8);

        $draft->waitingFor = $draft->missing()[0] ?? null;
        return [$draft, $events];
    }

    private function set(DraftOrder $d, array &$events, string $field, $value, float $conf): void
    {
        if ($value === null || $value === '') return;
        $prop = match ($field) {
            'address' => 'address', 'landmark' => 'landmark', 'delivery_area' => 'deliveryArea',
            'delivery_time' => 'deliveryTime', 'delivery_date' => 'deliveryDate',
            'payment_method' => 'paymentMethod', 'phone' => 'phone', 'notes' => 'notes', default => null,
        };
        if ($prop === null) return;
        $existingConf = $d->confidence[$field] ?? 0.0;
        if ($d->$prop !== null && $conf < $existingConf) return;        // keep the more confident value
        $d->$prop = $value;
        $d->confidence[$field] = $conf;
        $events[] = ['field' => $field, 'value' => $value];
    }
}
