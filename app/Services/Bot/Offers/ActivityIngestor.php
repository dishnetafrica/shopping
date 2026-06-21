<?php

namespace App\Services\Bot\Offers;

use App\Models\Tenant;

/**
 * Status Intelligence v16 — Passive Owner Learning from images.
 *
 * One owner image (poster / status / forward / broadcast) is mined two ways from a single vision
 * call: (1) structured OFFER data (daily_offer), and (2) business-STATE events read from the OCR
 * text ("Fresh jalebi ready" -> available, "Fafda sold out" -> sold_out, "Only 5 thali left" ->
 * low_stock). Everything learned is written to the Activity Feed, tagged with its source.
 */
class ActivityIngestor
{
    public function __construct(
        protected OfferVision $vision,
        protected DailyOfferService $offers,
        protected OfferUpdateService $updates,
        protected ActivityFeed $feed,
        protected ReviewQueueService $queue,
    ) {}

    /** Learn from an owner image. Returns a reply for the owner, or null if nothing was learned. */
    public function ingestOwnerImage(Tenant $tenant, string $b64, string $caption, string $source): ?string
    {
        $rich = $this->vision->extractRich($b64, $caption);            // populates the shared cache
        $text = trim($caption . ' ' . (string) ($rich['text'] ?? ''));

        $replies = [];

        // 1) Structured offer (uses the cached vision result — no second API call).
        $offer = $this->offers->ingestImage($tenant, $b64, $caption, '', $source);
        if ($offer) {
            $cur = (string) $tenant->setting('currency', 'UGX');
            $replies[] = OfferFormatter::ownerConfirm($offer, $cur);
            $conf = (int) ($rich['offer']['confidence'] ?? 85);
            $this->feed->record($tenant, $source, 'daily_offer', $conf,
                $text !== '' ? $text : (string) ($offer['title'] ?? ''),
                ['title' => $offer['title'] ?? null, 'offer_type' => $offer['type'] ?? null]);
        }

        if ($text === '' && trim($caption) !== '') $text = trim($caption);

        // 2) Business-state events read from the OCR text (v17 bands).
        if ($text !== '') {
            $sc = OwnerActivityScorer::score($text);
            if ($sc['event'] !== null) {
                $conf = (int) $sc['confidence'];
                $payload = ['item' => $sc['item'] ?? null, 'qty' => $sc['qty'] ?? null, 'price' => $sc['price'] ?? null, 'display' => $sc['display'] ?? null];

                if (ActivityBand::of($conf) === ActivityBand::AUTO) {
                    $replies[] = $this->updates->applyParsed($tenant, $sc, $text);
                    $this->feed->record($tenant, $source, $sc['event'], $conf, $text, $payload + ['applied' => true]);
                } elseif (ActivityBand::of($conf) === ActivityBand::REVIEW) {
                    $f = $this->feed->record($tenant, $source, $sc['event'], $conf, $text, $payload + ['applied' => false]);
                    if ($f) $this->queue->enqueue($tenant, (int) $f->id);
                    $replies[] = "📋 Saved *" . trim((string) ($sc['display'] ?? $sc['event'])) . "* ({$conf}%) to your review inbox.";
                } else {
                    $this->feed->record($tenant, $source, $sc['event'], $conf, $text, $payload + ['applied' => false]);
                }
            }
        }

        if (! $replies) return null;
        return implode("\n\n", array_values(array_unique($replies)));
    }
}
