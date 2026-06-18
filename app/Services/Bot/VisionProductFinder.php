<?php
namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Looks at a customer's product photo (plus any caption) and turns it into a short
 * catalogue search phrase + a confidence score. The phrase is fed into the normal
 * product search so the existing pick-list + cart flow handles it.
 *
 * Vision-only: it never sees or sets prices and never touches the cart. Returns null
 * when disabled, when the image can't be read, or when the photo isn't a buyable
 * product — so the bot can gracefully ask the customer to type the name instead.
 *
 * Identical images are cached (content hash) so resending the same photo costs no
 * OpenAI call.
 */
class VisionProductFinder
{
    /** Cache window for an image->query result. */
    private const CACHE_DAYS = 7;

    public function enabled(): bool
    {
        return (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
    }

    /**
     * @return array{query:string,confidence:int}|null
     */
    public function identify(string $base64Jpeg, string $caption = ''): ?array
    {
        if (! $this->enabled()) return null;

        $b64 = $this->cleanBase64($base64Jpeg);
        if ($b64 === '') return null;

        $model = (string) env('OPENAI_VISION_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini'));

        // (#4) Cache by image content + caption + model, so a resent photo is free.
        $cacheKey = 'imgq:'.$model.':'.sha1($b64.'|'.mb_strtolower(trim($caption)));
        $cached   = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'NONE' ? null : $cached;
        }

        $result = $this->callVision($b64, $caption, $model);
        Cache::put($cacheKey, $result ?? 'NONE', now()->addDays(self::CACHE_DAYS));

        return $result;
    }

    /**
     * @return array{query:string,confidence:int}|null
     */
    private function callVision(string $b64, string $caption, string $model): ?array
    {
        $hint = trim($caption) !== ''
            ? 'The customer also wrote a note with the photo: "'.mb_substr(trim($caption), 0, 140).'". Use it to refine the query. '
            : '';

        $system = <<<SYS
You identify a single retail / grocery product from a customer's photo for a shop in Uganda.
{$hint}Return STRICT JSON only, no prose, no markdown:
{"found":true|false,"query":"<short search phrase>","confidence":<0-100>,"brand":"<brand or empty>","size":"<size or empty>"}
Rules:
- "query" = the best 2-5 word phrase to find this in a catalogue: brand + product + size when visible (e.g. "Tilda basmati rice 5kg", "cooking oil 3L", "sing bhujia 200g").
- Read any text printed on the packaging to get the brand and size. Understand English / Gujarati / Hindi / Swahili names.
- Fold the customer's note into the query. If the note RULES OUT a variant (e.g. "not basmati"), simply LEAVE that word out of the query — never use negations like "not".
- "confidence" = how sure you are this is the actual product (0-100). Be honest: a clear branded pack = high; a generic bottle that could be oil/water/shampoo = low.
- Never invent a brand you cannot actually see. If unsure of brand, give the generic product type with lower confidence.
- If it is not a buyable product (blurry, a person, a place, a screenshot), set "found": false, "query": "", "confidence": 0.
SYS;

        try {
            $resp = OpenAI::chat()->create([
                'model'       => $model,
                'temperature' => 0,
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Identify this product.'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$b64]],
                    ]],
                ],
            ]);

            $content = trim(preg_replace('/```json|```/', '', $resp->choices[0]->message->content ?? ''));
            $data = json_decode($content, true);
            if (! is_array($data)) return null;
            if (array_key_exists('found', $data) && $data['found'] === false) return null;

            $query = trim((string) ($data['query'] ?? ''));
            if ($query === '') {
                $query = trim(implode(' ', array_filter([
                    (string) ($data['brand'] ?? ''),
                    (string) ($data['size'] ?? ''),
                ])));
            }
            $query = trim(preg_replace('/\s+/', ' ', $query));
            if ($query === '') return null;

            $confidence = (int) round((float) ($data['confidence'] ?? 0));
            $confidence = max(0, min(100, $confidence));

            return ['query' => mb_substr($query, 0, 80), 'confidence' => $confidence];
        } catch (\Throwable $e) {
            Log::warning('VisionProductFinder failed: '.$e->getMessage());
            return null;
        }
    }

    /** Accept a raw base64 string or a data: URI; return just the base64 payload. */
    private function cleanBase64(string $b64): string
    {
        $b64 = trim($b64);
        if ($b64 === '') return '';
        if (str_starts_with($b64, 'data:') && str_contains($b64, ',')) {
            $b64 = substr($b64, strpos($b64, ',') + 1);
        }
        return $b64;
    }
}
