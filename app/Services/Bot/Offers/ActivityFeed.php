<?php

namespace App\Services\Bot\Offers;

use App\Models\ActivityFeedItem;
use App\Models\Tenant;

/**
 * Status Intelligence v16 — Activity Feed.
 *
 * One unified, reviewable log of everything the bot has learned, across every channel
 * (direct messages, images, status posts, forwards). The owner never has to teach the bot
 * explicitly; this is the surface where they can see what it picked up.
 */
class ActivityFeed
{
    public function record(Tenant $tenant, string $source, string $eventType, int $confidence, string $rawContent, array $payload = []): void
    {
        try {
            ActivityFeedItem::create([
                'source'      => $source,
                'event_type'  => $eventType,
                'confidence'  => max(0, min(100, $confidence)),
                'raw_content' => mb_substr(trim($rawContent), 0, 500) ?: null,
                'payload'     => $payload ?: null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // the feed is a convenience surface; never let it break ingestion
        }
    }

    /** Recent feed entries for review (most recent first). */
    public function recent(Tenant $tenant, int $limit = 50): array
    {
        return ActivityFeedItem::orderByDesc('created_at')->limit(max(1, min(200, $limit)))->get()
            ->map(fn (ActivityFeedItem $r) => [
                'source'     => $r->source,
                'event'      => $r->event_type,
                'confidence' => (int) $r->confidence,
                'content'    => $r->raw_content,
                'payload'    => $r->payload,
                'at'         => $r->created_at?->toDateTimeString(),
            ])->all();
    }
}
