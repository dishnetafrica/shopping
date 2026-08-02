<?php

namespace App\Services\Vision;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * v4.8.2 — photo part-identification for the storefront (Storefront 2.0 Phase 3).
 *
 * The WhatsApp bot has identified parts from customer photos since the vision drop; this is the
 * same capability as a focused, single-purpose call the web storefront can use: one image + the
 * tenant's catalogue in, strict-JSON top-3 matches out. Same key conventions as AiBrain (per-tenant
 * `openai_api_key` setting overrides the global CloudBSS key) and the same vision-capable model
 * fallback. The prompt/parse halves are pure static functions so the harness can test them without
 * booting Laravel or calling the API.
 */
final class PartIdentifier
{
    /** Catalogue rows the model chooses from: [['id'=>..,'name'=>..,'brand'=>..,'category'=>..],…] */
    public static function prompt(array $parts): string
    {
        $lines = [];
        foreach ($parts as $p) {
            $lines[] = (int) $p['id'] . ' | ' . $p['name']
                . ' | ' . ($p['brand'] !== '' ? $p['brand'] : '-')
                . ' | ' . ($p['category'] !== '' ? $p['category'] : '-');
        }

        return "You identify motorcycle spare parts from a customer's photo for an East African parts shop.\n"
            . "CATALOGUE (id | name | brand | category):\n" . implode("\n", $lines) . "\n\n"
            . "TASK: Look at the photo. Decide which catalogue items it most plausibly shows.\n"
            . "Reply with STRICT JSON only, no markdown, exactly this shape:\n"
            . '{"seen":"<8-12 word plain description of what the photo shows>",'
            . '"matches":[{"id":<catalogue id>,"confidence":<0.0-1.0>,"reason":"<short why>"}]}' . "\n"
            . "Rules: at most 3 matches, best first; ids MUST come from the catalogue; when the photo "
            . "shows something not plausibly in the catalogue, return an empty matches array; never guess "
            . "a confident match from an unclear photo — a wrong part costs the customer a day.";
    }

    /** Parse the model's reply defensively. Returns ['seen'=>string,'matches'=>[['id','confidence','reason'],…]]. */
    public static function parse(string $raw): array
    {
        $out = ['seen' => '', 'matches' => []];
        $raw = trim($raw);
        if ($raw === '') return $out;
        // tolerate ```json fences and prose around the JSON
        if (preg_match('/\{.*\}/s', $raw, $m)) $raw = $m[0];
        $j = json_decode($raw, true);
        if (! is_array($j)) return $out;
        $out['seen'] = mb_substr(trim((string) ($j['seen'] ?? '')), 0, 160);
        foreach ((array) ($j['matches'] ?? []) as $mt) {
            if (! is_array($mt) || ! isset($mt['id'])) continue;
            $conf = (float) ($mt['confidence'] ?? 0);
            $out['matches'][] = [
                'id'         => (int) $mt['id'],
                'confidence' => max(0.0, min(1.0, $conf)),
                'reason'     => mb_substr(trim((string) ($mt['reason'] ?? '')), 0, 120),
            ];
            if (count($out['matches']) >= 3) break;
        }
        return $out;
    }

    /**
     * @param  string $imageB64  base64 image bytes (no data: prefix)
     * @return array{ok:bool, seen?:string, matches?:array, error?:string}
     */
    public function identify(Tenant $tenant, string $imageB64, string $mime): array
    {
        $parts = \App\Models\Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('active', true)->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'brand', 'category'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name,
                'brand' => (string) ($p->brand ?? ''), 'category' => (string) ($p->category ?? '')])
            ->all();
        if (! $parts) return ['ok' => false, 'error' => 'no_catalogue'];

        try {
            $model = (string) ($tenant->setting('ai_model', '') ?: env('OPENAI_MODEL', 'gpt-4o-mini'));
            if (! str_contains($model, '4o')) $model = 'gpt-4o-mini';   // must be vision-capable
            $tenantKey = (string) $tenant->setting('openai_api_key', '');
            $client    = $tenantKey !== '' ? \OpenAI::client($tenantKey) : \OpenAI::getFacadeRoot();
            $resp = $client->chat()->create([
                'model'       => $model,
                'temperature' => 0.1,
                'messages'    => [
                    ['role' => 'system', 'content' => self::prompt($parts)],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Identify this part.'],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$imageB64}"]],
                    ]],
                ],
            ]);
            $parsed = self::parse((string) ($resp->choices[0]->message->content ?? ''));
            return ['ok' => true, 'seen' => $parsed['seen'], 'matches' => $parsed['matches']];
        } catch (\Throwable $e) {
            Log::warning('PartIdentifier failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'vision_unavailable'];
        }
    }
}
