<?php
namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Turns a free-text WhatsApp message into a structured action using an LLM.
 * The model ONLY classifies intent and extracts item names + quantities — it
 * never sees or sets prices. Returns null when disabled or on any failure, so
 * BotBrain can fall back to deterministic keyword parsing.
 */
class BotNlu
{
    public function enabled(): bool
    {
        return (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
    }

    public function parse(Tenant $tenant, Conversation $convo, string $text): ?array
    {
        if (! $this->enabled()) return null;

        // Catalogue names help the model normalise "atta" -> "Wheat Flour (Atta) 5kg".
        $catalogue = Product::query()->where('active', true)
            ->orderBy('name')->limit(80)->pluck('name')->all();

        $cart = collect(is_array($convo->cart) ? $convo->cart : [])
            ->map(fn ($l) => "{$l['qty']}x {$l['name']}")->implode(', ') ?: '(empty)';

        $system = <<<SYS
You are the order-taking assistant for "{$tenant->name}", a grocery shop on WhatsApp.
Classify the customer's message and extract items. Reply with STRICT JSON only, no prose, no markdown.

Schema:
{"intent":"greet|add|remove|view_cart|checkout|clear|search|unknown","items":[{"query":"<product words>","qty":<int>}],"note":"<short optional clarification, may be empty>"}

Rules:
- "add": customer wants items (e.g. "2 kg sugar", "add rice and oil", "cheeni 1"). One entry per distinct product; default qty 1.
- "remove": customer wants to drop items from the basket.
- "view_cart": asking what's in the basket / their order.
- "checkout": wants to finish/place/confirm the order.
- "clear": empty the basket / start over.
- "search": asking what's available or the price of something, without committing to add.
- "greet": hi/hello/start/menu or general greeting.
- "unknown": anything else.
- Understand mixed English/Gujarati/Swahili and casual spelling. Map item words to the closest catalogue name when possible.
- Never invent prices or totals.

Catalogue (names only): {$this->shorten($catalogue)}
Current basket: {$cart}
SYS;

        try {
            $resp = OpenAI::chat()->create([
                'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $text],
                ],
            ]);
            $content = $resp->choices[0]->message->content ?? '';
            $content = trim(preg_replace('/```json|```/', '', $content));
            $data = json_decode($content, true);
            if (! is_array($data) || empty($data['intent'])) return null;
            $data['items'] = array_values(array_filter(array_map(function ($i) {
                if (! is_array($i) || empty($i['query'])) return null;
                return ['query' => (string) $i['query'], 'qty' => max(1, (int) ($i['qty'] ?? 1))];
            }, $data['items'] ?? [])));
            $data['note'] = (string) ($data['note'] ?? '');
            return $data;
        } catch (\Throwable $e) {
            Log::warning('BotNlu failed, falling back to keywords: '.$e->getMessage());
            return null;
        }
    }

    private function shorten(array $names): string
    {
        $s = implode(', ', $names);
        return mb_strlen($s) > 1800 ? mb_substr($s, 0, 1800).'…' : $s;
    }
}
