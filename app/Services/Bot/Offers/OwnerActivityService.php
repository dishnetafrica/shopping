<?php

namespace App\Services\Bot\Offers;

use App\Models\Conversation;
use App\Models\OwnerActivityLog;
use App\Models\Tenant;

/**
 * Status Intelligence v15-v17 — Owner Activity Learning.
 *
 * Scores a free-form owner message and acts on the v17 confidence band:
 *   >= 95  auto-apply        70-94  queue for review (panel inbox)        < 70  feed only
 *
 * Every detected candidate is written to owner_activity_log (the learning trail) and the unified
 * activity feed. Returns ['reply' => ?string, 'consume' => bool]. consume=false means "not an
 * update" — the message keeps flowing through normal handling.
 */
class OwnerActivityService
{
    public function __construct(
        protected OfferUpdateService $updates,
        protected ActivityFeed $feed,
        protected ReviewQueueService $queue,
    ) {}

    public function process(Tenant $tenant, Conversation $convo, string $text): array
    {
        $text = trim($text);
        if ($text === '') return ['reply' => null, 'consume' => false];

        $sc    = OwnerActivityScorer::score($text);
        $event = $sc['event'];
        $conf  = (int) $sc['confidence'];

        if ($event === null) {
            return ['reply' => null, 'consume' => false];
        }

        $payload = [
            'item'    => $sc['item'] ?? null,
            'qty'     => $sc['qty'] ?? null,
            'price'   => $sc['price'] ?? null,
            'display' => $sc['display'] ?? null,
        ];

        switch (ActivityBand::of($conf)) {
            case ActivityBand::AUTO:
                $reply = $this->updates->applyParsed($tenant, $sc, $text);
                $this->log($tenant, $text, $event, $conf, true);
                $this->feed->record($tenant, ActivitySource::MESSAGE, (string) $event, $conf, $text, $payload + ['applied' => true]);
                return ['reply' => $reply, 'consume' => true];

            case ActivityBand::REVIEW:
                $f = $this->feed->record($tenant, ActivitySource::MESSAGE, (string) $event, $conf, $text, $payload + ['applied' => false]);
                if ($f) $this->queue->enqueue($tenant, (int) $f->id);
                $this->log($tenant, $text, $event, $conf, null);
                return ['reply' => "📋 Noted *" . $this->summary($sc) . "* ({$conf}%) — saved to your review inbox in the panel.", 'consume' => true];

            default: // FEED
                $this->log($tenant, $text, $event, $conf, false);
                $this->feed->record($tenant, ActivitySource::MESSAGE, (string) $event, $conf, $text, $payload + ['applied' => false]);
                return ['reply' => null, 'consume' => false];
        }
    }

    private function summary(array $sc): string
    {
        $d = trim((string) ($sc['display'] ?? '')) ?: 'Lunch';
        return match ($sc['event'] ?? '') {
            'sold_out'     => "{$d} is sold out",
            'available'    => "Fresh {$d} is available now",
            'ready'        => "{$d} is ready",
            'low_stock'    => "Only " . (int) ($sc['qty'] ?? 0) . " {$d} left",
            'price_change' => "{$d} price updated",
            default        => "{$d} update",
        };
    }

    private function log(Tenant $tenant, string $message, ?string $event, int $conf, ?bool $approved): void
    {
        try {
            OwnerActivityLog::create([
                'message'        => mb_substr($message, 0, 500),
                'detected_event' => $event,
                'confidence'     => max(0, min(100, $conf)),
                'approved'       => $approved,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // logging is best-effort
        }
    }
}
