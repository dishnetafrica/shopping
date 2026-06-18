<?php
namespace App\Services\Bot;

use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Looks at a customer's product photo and turns it into a short catalogue search
 * phrase (e.g. "Tilda basmati rice 5kg"). The result is fed into the normal product
 * search so the existing pick-list + cart flow handles it.
 *
 * Vision-only: it never sees or sets prices. Returns null when disabled, when the
 * image can't be read, or when the photo isn't a buyable product — so the bot can
 * gracefully ask the customer to type the name instead.
 */
class VisionProductFinder
{
    public function enabled(): bool
    {
        return (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
    }

    public function identify(string $base64Jpeg, string $caption = ''): ?string
    {
        if (! $this->enabled()) return null;

        $b64 = $this->cleanBase64($base64Jpeg);
        if ($b64 === '') return null;

        $hint = trim($caption) !== ''
            ? 'The customer also wrote: "'.mb_substr(trim($caption), 0, 120).'". '
            : '';

        $system = <<<SYS
You identify a single retail / grocery product from a customer's photo for a shop in Uganda.
{$hint}Return STRICT JSON only, no prose, no markdown:
{"found":true|false,"query":"<short search phrase>","category":"<one word or empty>","brand":"<brand or empty>","size":"<size or empty>"}
Rules:
- "query" = the best 2-5 word phrase to find this in a catalogue: brand + product + size when visible (e.g. "Tilda basmati rice 5kg", "cooking oil 3L", "sing bhujia 200g").
- Read any text printed on the packaging to get the brand and size.
- Understand English / Gujarati / Hindi / Swahili product names.
- If you cannot tell it is a buyable product (blurry, a person, a place, a screenshot), set "found": false and "query": "".
- Never invent a brand you cannot actually see.
SYS;

        try {
            $resp = OpenAI::chat()->create([
                'model'       => env('OPENAI_VISION_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
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
                    (string) ($data['category'] ?? ''),
                    (string) ($data['size'] ?? ''),
                ])));
            }
            $query = trim(preg_replace('/\s+/', ' ', $query));

            return $query !== '' ? mb_substr($query, 0, 80) : null;
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
