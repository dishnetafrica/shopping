<?php

namespace App\Services\Bot\Offers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Status Intelligence — vision OCR + structured extraction. Reads an owner's menu poster /
 * status image and returns the canonical offer array (via OfferExtractor::fromVision). One
 * call does both OCR and structuring. Identical images are cached. Returns null when the
 * model is disabled, the image is unreadable, or it isn't a menu/offer.
 *
 * Mirrors VisionProductFinder's setup so it uses the same OpenAI key and caching discipline.
 */
class OfferVision
{
    private const CACHE_DAYS = 1;   // menus change daily; don't cache a poster longer than a day

    public function enabled(): bool
    {
        return (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
    }

    /** @return array|null canonical offer array, or null */
    public function extract(string $base64Jpeg, string $caption = ''): ?array
    {
        return $this->extractRich($base64Jpeg, $caption)['offer'];
    }

    /**
     * Like extract(), but also returns the verbatim OCR text so the caller can mine business-state
     * events ("Fresh jalebi ready", "Fafda sold out") from posters/status images.
     *
     * @return array{offer:?array,text:string}
     */
    public function extractRich(string $base64Jpeg, string $caption = ''): array
    {
        $empty = ['offer' => null, 'text' => ''];
        if (! $this->enabled()) return $empty;
        $b64 = $this->cleanBase64($base64Jpeg);
        if ($b64 === '') return $empty;

        $model = (string) env('OPENAI_VISION_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini'));
        $cacheKey = 'offerimg:json:' . $model . ':' . sha1($b64 . '|' . mb_strtolower(trim($caption)));
        $json = Cache::get($cacheKey);
        if ($json === null) {
            $json = $this->callVision($b64, $caption, $model) ?? [];
            Cache::put($cacheKey, $json, now()->addDays(self::CACHE_DAYS));
        }

        $offer = (is_array($json) && $json) ? OfferExtractor::fromVision($json) : null;
        if ($offer && empty($offer['found'])) $offer = null;

        $text = is_array($json) ? trim((string) ($json['raw_text'] ?? '')) : '';
        if ($text === '' && $offer) {
            $parts = array_filter(array_merge([(string) ($offer['title'] ?? '')], (array) ($offer['items'] ?? [])));
            $text = trim(implode(' ', $parts));
        }

        return ['offer' => $offer, 'text' => $text];
    }

    private function callVision(string $b64, string $caption, string $model): ?array
    {
        $hint = trim($caption) !== ''
            ? 'The owner also wrote: "' . mb_substr(trim($caption), 0, 200) . '". Use it. '
            : '';

        $types = implode('|', OfferTypeClassifier::TYPES);
        $system = <<<SYS
You read a restaurant / snack shop's daily menu poster, WhatsApp status, or offer image for a shop in Uganda and turn it into structured data. The owner posts these instead of editing a catalogue.
{$hint}Return STRICT JSON only, no prose, no markdown:
{"found":true|false,"title":"<dish or offer name>","price":<integer or null>,"currency":"<UGX|SSP|KES|USD|INR or empty>","items":["<item>", ...],"day":"<weekday or 'today' or empty>","offer_type":"<{$types}>","description":"<one short line or empty>","raw_text":"<ALL text visible on the image, verbatim>","confidence":<0-100>}
Rules:
- "title" = the main dish / offer name, e.g. "Kathiyawadi Thali", "Weekend Chole Bhature", "Diwali Sweet Box". Read English, Gujarati, Hindi, Swahili text on the image.
- "raw_text" = transcribe ALL words printed on the image exactly as written (including phrases like "Fresh", "Ready", "Sold Out", "Only 5 left"). Always fill this in, even when found is false.
- "price" = the meal/offer price as a plain integer (e.g. 15000). Ignore phone numbers and "ONLY"/"/-" decoration. null if no price is shown.
- "items" = each dish listed (e.g. "Dhokli Nu Shak","Mag Sabji","5 Chapati","Dal","Rice","Papad","Salad","Chaas"). Keep counts that belong to a dish. Empty array if none.
- "offer_type": daily_thali for a set meal/thali/lunch menu; weekend_offer for Saturday/Sunday specials; festival_offer for Diwali/Eid/etc.; fresh_today for a fresh/just-made batch; special_offer otherwise.
- "day": a weekday if printed, else "today" if it says today/aaj, else empty.
- If the image is not a menu/offer (a person, a place, a random product photo, a screenshot), set "found": false and everything empty/0.
SYS;

        try {
            $resp = OpenAI::chat()->create([
                'model'       => $model,
                'temperature' => 0,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Extract the offer from this image.'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $b64]],
                    ]],
                ],
            ]);

            $content = trim(preg_replace('/```json|```/', '', $resp->choices[0]->message->content ?? ''));
            $data = json_decode($content, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Log::warning('OfferVision failed: ' . $e->getMessage());
            return null;
        }
    }

    private function cleanBase64(string $b64): string
    {
        $b64 = trim($b64);
        if (str_contains($b64, ',') && str_starts_with($b64, 'data:')) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        return preg_replace('/\s+/', '', $b64) ?: '';
    }
}
