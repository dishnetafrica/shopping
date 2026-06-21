<?php

namespace App\Services\Bot\Offers;

use App\Models\ActivityFeedItem;
use App\Models\ActivityReviewItem;
use App\Models\Tenant;

/**
 * Status Intelligence v17 — Activity Review Inbox.
 *
 * Detections in the 70-94 band are queued here instead of being applied. The owner reviews each
 * in the Seller Panel and Approves (apply), Rejects (discard), or Edits (apply a corrected event).
 */
class ReviewQueueService
{
    public const PENDING  = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const EDITED   = 'edited';

    public function __construct(protected OfferUpdateService $updates) {}

    public function enqueue(Tenant $tenant, int $feedItemId): void
    {
        if ($feedItemId <= 0) return;
        ActivityReviewItem::create([
            'feed_item_id' => $feedItemId,
            'status'       => self::PENDING,
            'created_at'   => now(),
        ]);
    }

    /** Pending items joined with their feed data, shaped for the panel inbox. */
    public function pending(Tenant $tenant, int $limit = 100): array
    {
        $rows = ActivityReviewItem::where('status', self::PENDING)->orderByDesc('id')->limit(max(1, min(200, $limit)))->get();
        if ($rows->isEmpty()) return [];

        $feed = ActivityFeedItem::whereIn('id', $rows->pluck('feed_item_id')->all())->get()->keyBy('id');

        $out = [];
        foreach ($rows as $q) {
            $f = $feed[$q->feed_item_id] ?? null;
            if (! $f) continue;
            $p = is_array($f->payload) ? $f->payload : [];
            $out[] = [
                'id'         => (int) $q->id,
                'source'     => (string) $f->source,
                'message'    => (string) $f->raw_content,
                'event'      => (string) $f->event_type,
                'item'       => $p['item'] ?? null,
                'qty'        => $p['qty'] ?? null,
                'price'      => $p['price'] ?? null,
                'confidence' => (int) $f->confidence,
                'suggested'  => $this->suggested((string) $f->event_type, (string) ($p['display'] ?? $p['item'] ?? ''), $p['qty'] ?? null),
                'at'         => $f->created_at?->toDateTimeString(),
            ];
        }
        return $out;
    }

    public function approve(Tenant $tenant, int $id, string $by = ''): bool
    {
        $q = ActivityReviewItem::where('status', self::PENDING)->find($id);
        if (! $q) return false;

        $f = ActivityFeedItem::find($q->feed_item_id);
        if ($f) $this->applyFeed($tenant, $f);

        return $this->close($q, self::APPROVED, $by);
    }

    public function reject(Tenant $tenant, int $id, string $by = ''): bool
    {
        $q = ActivityReviewItem::where('status', self::PENDING)->find($id);
        if (! $q) return false;

        return $this->close($q, self::REJECTED, $by);
    }

    /** Apply a corrected event. $edits may set event/item/qty/price. */
    public function edit(Tenant $tenant, int $id, array $edits, string $by = ''): bool
    {
        $q = ActivityReviewItem::where('status', self::PENDING)->find($id);
        if (! $q) return false;

        $f = ActivityFeedItem::find($q->feed_item_id);
        if ($f) {
            $p     = is_array($f->payload) ? $f->payload : [];
            $event = trim((string) ($edits['event'] ?? $f->event_type)) ?: $f->event_type;
            $item  = array_key_exists('item', $edits) ? (string) $edits['item'] : (string) ($p['item'] ?? '');
            $qty   = array_key_exists('qty', $edits) ? ($edits['qty'] !== null ? (int) $edits['qty'] : null) : ($p['qty'] ?? null);
            $price = array_key_exists('price', $edits) ? ($edits['price'] !== null ? (int) $edits['price'] : null) : ($p['price'] ?? null);

            $this->updates->applyParsed($tenant, [
                'event' => $event, 'item' => $item, 'qty' => $qty, 'price' => $price,
                'display' => $item !== '' ? ucwords($item) : '',
            ], (string) $f->raw_content);

            $f->event_type = $event;
            $f->payload = array_merge($p, ['item' => $item, 'qty' => $qty, 'price' => $price, 'applied' => true, 'edited' => true]);
            $f->save();
        }

        return $this->close($q, self::EDITED, $by);
    }

    private function close(ActivityReviewItem $q, string $status, string $by): bool
    {
        $q->status = $status;
        $q->approved_by = $by !== '' ? $by : 'owner';
        $q->approved_at = now();
        $q->save();
        return true;
    }

    private function applyFeed(Tenant $tenant, ActivityFeedItem $f): void
    {
        // daily_offer feed items are already applied at capture; only state events need applying.
        if ($f->event_type === 'daily_offer' || in_array($f->event_type, OfferTypeClassifier::TYPES, true)) return;

        $p = is_array($f->payload) ? $f->payload : [];
        $this->updates->applyParsed($tenant, [
            'event'   => (string) $f->event_type,
            'item'    => (string) ($p['item'] ?? ''),
            'qty'     => $p['qty'] ?? null,
            'price'   => $p['price'] ?? null,
            'display' => (string) ($p['display'] ?? ucwords((string) ($p['item'] ?? ''))),
        ], (string) $f->raw_content);

        $f->payload = array_merge($p, ['applied' => true]);
        $f->save();
    }

    private function suggested(string $event, string $display, $qty): string
    {
        $d = trim($display) ?: 'item';
        return match ($event) {
            'sold_out'     => "Mark {$d} sold out",
            'available'    => "Mark {$d} available",
            'ready'        => "Mark {$d} ready",
            'low_stock'    => "Set {$d} low stock" . ($qty ? " ({$qty} left)" : ''),
            'price_change' => "Update {$d} price",
            'daily_offer'  => "Publish {$d} offer",
            default        => "Apply {$d}",
        };
    }
}
