<?php
namespace App\Apps\Orders;

use App\Apps\Orders\Dto\CartLine;
use App\Apps\Orders\Dto\DraftOrder;
use App\Apps\Orders\Dto\EntityBundle;

/**
 * Applies a message's extracted entities onto the persistent draft, recording PROVENANCE for every
 * field (source / confidence / message_id / updated_at) and respecting LOCKS: once a field is
 * locked (post-confirmation), a new value becomes a pending modification instead of a silent edit.
 * Returns the timeline events describing the change.
 */
class DraftMerge
{
    /**
     * @return array{0:DraftOrder,1:array<int,array{field:string,value:mixed}>}
     */
    public function apply(DraftOrder $draft, EntityBundle $b, string $source = 'whatsapp', ?int $messageId = null): array
    {
        $events = [];

        foreach ($b->products as $p) {
            $line = new CartLine(
                name: $p['name'], weightGrams: $p['weight_grams'] ?? null,
                quantity: max(1, (int) ($p['qty'] ?? 1)), confidence: (float) ($p['confidence'] ?? 1.0),
            );
            if ($draft->isLocked('lines')) {
                $draft->addPending('lines', $line->toArray(), $source, $messageId);
                $events[] = ['field' => 'pending_modification', 'value' => ['field' => 'lines', 'line' => $line->toArray()]];
                continue;
            }
            $replaced = false;
            foreach ($draft->lines as $i => $existing) {
                if ($existing->key() === $line->key()) { $draft->lines[$i] = $line; $replaced = true; break; }
            }
            if (! $replaced) $draft->lines[] = $line;
            $events[] = ['field' => 'line', 'value' => $line->toArray()];
        }
        if ($b->products && ! $draft->isLocked('lines')) {
            $this->stamp($draft, 'lines', $b->confidence['lines'] ?? 0.9, $source, $messageId);
        }

        $this->set($draft, $events, 'address', $b->address, $b->confidence['address'] ?? 0, $source, $messageId);
        $this->set($draft, $events, 'landmark', $b->landmark, $b->confidence['landmark'] ?? 0, $source, $messageId);
        $this->set($draft, $events, 'delivery_area', $b->deliveryArea, $b->confidence['delivery_area'] ?? 0, $source, $messageId);
        $this->set($draft, $events, 'delivery_time', $b->deliveryTime, $b->confidence['delivery_time'] ?? 0, $source, $messageId);
        $this->set($draft, $events, 'delivery_date', $b->deliveryDate, 0.8, $source, $messageId);
        $this->set($draft, $events, 'payment_method', $b->paymentMethod, $b->confidence['payment_method'] ?? 0, $source, $messageId);
        $this->set($draft, $events, 'phone', $b->phone, $b->confidence['phone'] ?? 0, $source, $messageId);
        $this->set($draft, $events, 'notes', $b->notes, 0.8, $source, $messageId);

        $draft->waitingFor = $draft->missing()[0] ?? null;
        return [$draft, $events];
    }

    private function set(DraftOrder $d, array &$events, string $field, $value, float $conf, string $source, ?int $messageId): void
    {
        if ($value === null || $value === '') return;
        $prop = $this->prop($field);
        if ($prop === null) return;

        if ($d->isLocked($field)) {                                   // locked → pending, never silent edit
            if ($d->$prop !== $value) {
                $d->addPending($field, $value, $source, $messageId);
                $events[] = ['field' => 'pending_modification', 'value' => ['field' => $field, 'value' => $value]];
            }
            return;
        }

        $existingConf = $d->confidence[$field] ?? 0.0;
        if ($d->$prop !== null && $conf < $existingConf) return;       // keep the more confident value
        $d->$prop = $value;
        $this->stamp($d, $field, $conf, $source, $messageId);
        $events[] = ['field' => $field, 'value' => $value];
    }

    private function stamp(DraftOrder $d, string $field, float $conf, string $source, ?int $messageId): void
    {
        $d->confidence[$field] = $conf;
        $d->provenance[$field] = [
            'source' => $source, 'confidence' => $conf, 'message_id' => $messageId, 'updated_at' => gmdate('c'),
        ];
    }

    private function prop(string $field): ?string
    {
        return match ($field) {
            'address' => 'address', 'landmark' => 'landmark', 'delivery_area' => 'deliveryArea',
            'delivery_time' => 'deliveryTime', 'delivery_date' => 'deliveryDate',
            'payment_method' => 'paymentMethod', 'phone' => 'phone', 'notes' => 'notes', default => null,
        };
    }
}
