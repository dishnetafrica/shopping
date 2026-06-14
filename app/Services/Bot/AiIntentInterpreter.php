<?php

namespace App\Services\Bot;

/**
 * AiIntentInterpreter — an OPTIONAL "understanding" layer for messages the deterministic
 * parser can't confidently handle. It is NOT an ordering engine.
 *
 * Hard safety contract (enforced by validate(), not by trust in the model):
 *   - The AI returns INTENT ONLY. It can never add/remove products, change quantities, create
 *     orders, calculate totals or delivery fees, or check out.
 *   - Only "understanding" intents are honoured (ALLOWED_INTENTS). Any other intent the model
 *     emits — including anything that looks like a command ("checkout", "add", "set_quantity")
 *     — is discarded and downgraded to 'unclear', so the bot ASKS instead of acting.
 *   - Every honoured intent maps (via toAction) to an existing, deterministic, read-only handler.
 *     The actual commerce (adding the chosen item, totals, checkout) always runs through the
 *     rule engine after the customer confirms.
 *   - Below MIN_CONFIDENCE the intent becomes 'unclear' → the bot asks a clarifying question.
 *   - Any failure (no key, network error, bad JSON) returns null → the bot falls back to rules /
 *     clarify. The interpreter can never break or hijack the conversation.
 *
 * Disabled unless an API key is configured, so it is a pure no-op until explicitly switched on.
 */
class AiIntentInterpreter
{
    /** ONLY these intents are ever honoured. None of them execute commerce. */
    public const ALLOWED_INTENTS = [
        'recommend_product', 'compare_value', 'delivery_question',
        'location_pin_question', 'product_search', 'greeting', 'unclear',
    ];

    private const MIN_CONFIDENCE = 0.70;

    public function __construct(
        private string $apiKey = '',
        private string $model = 'gpt-4o-mini',
        private string $endpoint = 'https://api.openai.com/v1/chat/completions',
    ) {
    }

    public function isEnabled(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * Interpret a message into a SAFE, validated intent, or null to fall back to rules/clarify.
     * Never throws — any failure degrades to null.
     */
    public function interpret(string $message, array $context = []): ?array
    {
        if (! $this->isEnabled()) return null;
        try {
            $raw = $this->callModel($message, $context);
        } catch (\Throwable $e) {
            return null;   // network / parse / anything -> rules handle it
        }
        return is_array($raw) ? self::validate($raw) : null;
    }

    /**
     * Strict schema + safety gate. Pure & testable. Returns a clean intent array; downgrades
     * anything unknown, execute-y, or low-confidence to 'unclear' so the bot asks, never acts.
     */
    public static function validate($json): ?array
    {
        if (! is_array($json)) return null;

        $intent = is_string($json['intent'] ?? null) ? strtolower(trim($json['intent'])) : '';
        if (! in_array($intent, self::ALLOWED_INTENTS, true)) {
            // unknown OR a command the model invented (add/checkout/...) -> never honour it
            return ['intent' => 'unclear', 'confidence' => 0.0];
        }

        $conf = max(0.0, min(1.0, (float) ($json['confidence'] ?? 0)));
        $out  = ['intent' => $intent, 'confidence' => $conf];

        // copy only the whitelisted, non-executing fields for this intent (a stray "quantity" or
        // "total" the model adds is silently dropped — it can never reach the cart)
        $allow = [
            'recommend_product'     => ['category', 'exclude', 'usage', 'family_size', 'budget', 'size'],
            'compare_value'         => ['brand', 'category', 'metric'],
            'delivery_question'     => ['location'],
            'location_pin_question' => [],
            'product_search'        => ['query'],
            'greeting'              => [],
            'unclear'               => ['category'],
        ][$intent] ?? [];
        foreach ($allow as $f) {
            if (isset($json[$f])) $out[$f] = self::sanitize($f, $json[$f]);
        }

        // confidence gate: too unsure -> ask, don't guess (one clarification beats a wrong order)
        if ($conf < self::MIN_CONFIDENCE && $intent !== 'unclear') {
            $ask = ['intent' => 'unclear', 'confidence' => $conf, 'wanted' => $intent];
            if (isset($out['category'])) $ask['category'] = $out['category'];
            return $ask;
        }

        return $out;
    }

    /** Coerce a field to a safe scalar/list and cap lengths. Pure. */
    private static function sanitize(string $field, $v)
    {
        if ($field === 'family_size') return max(1, min(50, (int) $v));
        if (is_array($v)) {
            return array_values(array_filter(array_map(
                fn ($x) => is_scalar($x) ? mb_substr(mb_strtolower(trim((string) $x)), 0, 32) : '',
                $v
            ), fn ($x) => $x !== ''));
        }
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, 64) : '';
    }

    /**
     * Map a validated intent to a DETERMINISTIC, read-only handler descriptor. The caller routes
     * 'handler' to the existing rule-engine handler — the AI never executes anything itself. Pure.
     */
    public static function toAction(array $intent): array
    {
        switch ($intent['intent'] ?? 'unclear') {
            case 'recommend_product':
                return ['handler' => 'recommend', 'context' => [
                    'product'     => $intent['category']    ?? '',
                    'exclude'     => $intent['exclude']     ?? [],
                    'usage'       => $intent['usage']       ?? null,
                    'family_size' => $intent['family_size'] ?? null,
                    'budget'      => $intent['budget']      ?? null,
                    'size'        => $intent['size']        ?? null,
                ]];
            case 'compare_value':
                return ['handler' => 'value',
                        'subject' => $intent['brand'] ?? ($intent['category'] ?? ''),
                        'metric'  => $intent['metric'] ?? 'price_per_kg'];
            case 'delivery_question':
                return ['handler' => 'delivery', 'location' => $intent['location'] ?? ''];
            case 'location_pin_question':
                return ['handler' => 'location_help'];
            case 'product_search':
                return ['handler' => 'search', 'query' => $intent['query'] ?? ''];
            case 'greeting':
                return ['handler' => 'greeting'];
            default:
                return ['handler' => 'clarify', 'category' => $intent['category'] ?? ''];
        }
    }

    /** The strict, JSON-only system prompt. Read-only intents, no execution, ask when unsure. */
    public static function systemPrompt(): string
    {
        $intents = implode(', ', self::ALLOWED_INTENTS);
        return <<<PROMPT
You are an intent classifier for a WhatsApp grocery shop assistant. You ONLY classify the
customer's intent. You NEVER take actions, never add or remove products, never set quantities,
never create orders, never calculate prices or totals, never check out.

Return ONLY a single JSON object, no prose, no markdown. Schema:
{
  "intent": one of [$intents],
  "confidence": number 0..1,
  // intent-specific fields:
  // recommend_product: "category" (e.g. "rice"), "exclude" (array), "usage" ("daily"|"special"|"cooking"),
  //                    "family_size" (int), "budget" ("low"|"high"), "size" (e.g. "5kg")
  // compare_value: "brand" or "category", "metric" ("price_per_kg"|"price_per_litre")
  // delivery_question: "location"
  // product_search: "query"
}
Rules:
- If the customer is asking which product is good/best/popular or describing what they need, use recommend_product.
- If they ask which is cheapest/best value per kg or litre, use compare_value.
- If you are less than 0.7 confident, use intent "unclear" so the assistant can ask a question.
- Never invent an intent that is not in the allowed list. If unsure, use "unclear".
PROMPT;
    }

    /**
     * Live model call. Isolated on purpose: requires a configured API key + endpoint and is the
     * ONLY part that talks to a network. Returns the decoded JSON object or null. Not exercised by
     * the offline test suite — verify on staging with a real key. Fails safe (null) on any error.
     */
    private function callModel(string $message, array $context): ?array
    {
        $payload = [
            'model'           => $this->model,
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => self::systemPrompt()],
                ['role' => 'user',   'content' => $message],
            ],
        ];

        $resp = \Illuminate\Support\Facades\Http::withToken($this->apiKey)
            ->timeout(8)
            ->acceptJson()
            ->post($this->endpoint, $payload);

        if (! $resp->successful()) return null;
        $content = $resp->json('choices.0.message.content');
        if (! is_string($content) || $content === '') return null;

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }
}
