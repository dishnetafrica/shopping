<?php

namespace App\Services\Isp;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Product;
use App\Models\SalesCoachSuggestion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;
use App\Services\Travel\Conversation\ReplyGuard;
use Illuminate\Support\Facades\Schema;

/**
 * AI Sales Coach (DishNet South Sudan plan, Phase 4). For a salesperson in a customer's chat: the next
 * reply to send, WHY, and anything to check first. It never sends anything — the salesperson edits the
 * suggestion in the composer and sends it through the normal reply path.
 *
 * Same knowledge rule as the customer-facing bot: only the Sales Brain's PUBLISHED knowledge and the
 * catalogue's switched-on products. The suggestion goes through IspReplyGuard, so an amount or a
 * coverage claim management has not published is removed before the salesperson sees it.
 * Every suggestion is recorded (who asked, what was suggested) for quality monitoring.
 */
class SalesCoach
{
    public const STEPS = ['qualify', 'recommend', 'quote', 'handle_objection', 'close', 'follow_up', 'support_handover'];

    private const HISTORY = 20;

    public function __construct(private SalesBrain $brain) {}

    public static function available(): bool
    {
        return (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
    }

    /** @return array{ok:bool, error?:string, message?:string, suggestion?:string, why?:string, check?:string, next_step?:string, used?:string[], removed?:int, id?:int} */
    public function suggest(Tenant $tenant, Conversation $convo, User $user): array
    {
        if (! SalesOwnership::enabled($tenant)) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        if (! self::available() && ! $tenant->setting('openai_api_key')) {
            return ['ok' => false, 'error' => 'ai_not_configured', 'message' => 'The AI key is not configured, so the coach cannot suggest a reply.'];
        }
        $phone = (string) $convo->customer_phone;
        $msgs = Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_phone', $phone)
            ->orderByDesc('id')->limit(self::HISTORY)->get(['direction', 'sender', 'body'])->reverse()
            ->map(fn ($m) => ['dir' => (string) $m->direction, 'sender' => (string) $m->sender, 'body' => (string) $m->body])->values()->all();
        if (! array_filter($msgs, fn ($m) => $m['dir'] === 'in')) {
            return ['ok' => false, 'error' => 'nothing_to_answer', 'message' => 'The customer has not written anything yet.'];
        }

        $published = $this->brain->published($tenant);
        $catalogue = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0)
            ->orderBy('category')->orderBy('name')->limit(200)->get(['name', 'price', 'billing', 'service_line'])
            ->map(fn ($p) => ['name' => (string) $p->name, 'price' => (float) $p->price, 'billing' => (string) ($p->billing ?? ''), 'service_line' => (string) ($p->service_line ?? '')])->all();
        $customer = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('phone', $phone)->first();

        $raw = $this->ask($tenant, self::messages((string) $tenant->name, (string) ($tenant->setting('currency', 'USD')), $published, $catalogue, $msgs,
            trim((string) ($customer?->name ?? '')), (string) $user->name));
        $parsed = self::parse((string) $raw);
        if ($parsed === null) {
            return ['ok' => false, 'error' => 'no_suggestion', 'message' => 'The coach could not produce a suggestion. Try again.'];
        }
        [$amounts, $places] = IspSalesTurn::allowed($published);
        $customerText = implode("\n", array_column(array_filter($msgs, fn ($m) => $m['dir'] === 'in'), 'body'));
        $out = self::finish($parsed, array_merge($amounts, array_column($catalogue, 'price'), ReplyGuard::amounts($customerText)), $places);

        $id = $this->record($tenant, $convo, $user, $out);

        return ['ok' => true] + $out + ($id ? ['id' => $id] : []);
    }

    /** The one AI call. Separate so tests can replace it. Returns the model's raw text, or null. */
    protected function ask(Tenant $tenant, array $messages): ?string
    {
        try {
            $key = (string) $tenant->setting('openai_api_key', '');
            $client = $key !== '' ? \OpenAI::client($key) : \OpenAI\Laravel\Facades\OpenAI::getFacadeRoot();
            $resp = $client->chat()->create([
                'model' => (string) ($tenant->setting('ai_model', '') ?: env('OPENAI_MODEL', 'gpt-4o-mini')),
                'temperature' => 0.3,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);

            return (string) ($resp->choices[0]->message->content ?? '');
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ── pure ─────────────────────────────────────────────────────────────

    /** PURE. The coach's instructions, the approved knowledge, the catalogue and the conversation. */
    public static function messages(string $company, string $currency, array $published, array $catalogue, array $history, string $customerName, string $salesperson): array
    {
        $system = "You are the sales coach for the salespeople of {$company}, an Internet provider selling Starlink and Fiber. "
            . "A salesperson ({$salesperson}) is chatting with a customer on WhatsApp and asks you for the NEXT message to send.\n"
            . "RULES:\n"
            . "- Use ONLY the APPROVED SALES KNOWLEDGE and the CATALOGUE below. Never invent a price, package, speed, coverage, term, discount or date. If what the customer needs is not written below, the reply should ask the customer a useful question or say the team will confirm it, and you must name it in \"check\".\n"
            . "- Coverage: only places listed as covered are covered; anywhere else a site survey is needed.\n"
            . "- Move the sale one step forward: qualify (service, exact area + landmark, home or business, number of users and use), recommend ONE option with the reason, quote, handle an objection, close, follow up — or hand over to support if it is a service problem.\n"
            . "- WhatsApp style: short (2–5 lines), warm, plain words, one question at a time, the customer's language, written as the salesperson in the first person.\n"
            . "Answer ONLY with JSON: {\"reply\": the message to send, \"why\": one to three short sentences for the salesperson explaining the reasoning, "
            . "\"next_step\": one of " . implode('|', self::STEPS) . ", \"used\": [titles of the knowledge or catalogue items you relied on], "
            . "\"check\": anything the salesperson must confirm before sending, or an empty string}.\n\n"
            . IspSalesTurn::knowledgeBlock($published)
            . self::catalogueBlock($catalogue, $currency);

        $lines = [];
        foreach ($history as $m) {
            $who = $m['dir'] === 'in' ? 'Customer' : ($m['sender'] === 'bot' ? 'Bot' : ($m['sender'] === 'system' ? 'System' : 'Salesperson'));
            $body = trim(preg_replace('/\s+/', ' ', (string) $m['body']) ?? '');
            if ($body !== '') {
                $lines[] = $who . ': ' . mb_substr($body, 0, 600);
            }
        }
        $user = ($customerName !== '' ? "Customer name: {$customerName}\n" : '') . "CONVERSATION (oldest first):\n" . implode("\n", $lines) . "\n\nSuggest the salesperson's next message.";

        return [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    }

    public static function catalogueBlock(array $catalogue, string $currency): string
    {
        if (! $catalogue) {
            return "CATALOGUE: no products are switched on yet — do not state any price.\n";
        }
        $out = "CATALOGUE (switched-on products, {$currency}):\n";
        foreach ($catalogue as $p) {
            $out .= '- ' . $p['name'] . ': ' . rtrim(rtrim(number_format((float) $p['price'], 2, '.', ''), '0'), '.')
                . ($p['billing'] === 'monthly' ? ' per month' : ' one-time') . ($p['service_line'] !== '' ? ' [' . $p['service_line'] . ']' : '') . "\n";
        }

        return $out;
    }

    /** PURE. The model's JSON → a clean suggestion, or null if there is no usable reply. */
    public static function parse(string $raw): ?array
    {
        $raw = trim($raw);
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $j = json_decode($raw, true);
        if (! is_array($j)) {
            return null;
        }
        $reply = trim((string) ($j['reply'] ?? ''));
        if ($reply === '') {
            return null;
        }
        $step = (string) ($j['next_step'] ?? '');

        return [
            'suggestion' => mb_substr($reply, 0, 1500),
            'why' => mb_substr(trim((string) ($j['why'] ?? '')), 0, 600),
            'next_step' => in_array($step, self::STEPS, true) ? $step : '',
            'used' => array_values(array_slice(array_filter(array_map(fn ($u) => mb_substr(trim((string) $u), 0, 120), (array) ($j['used'] ?? [])), fn ($u) => $u !== ''), 0, 8)),
            'check' => mb_substr(trim((string) ($j['check'] ?? '')), 0, 400),
        ];
    }

    /** PURE. Guard the suggestion; tell the salesperson what was removed and why. */
    public static function finish(array $parsed, array $allowedAmounts, array $coveredPlaces): array
    {
        $g = IspReplyGuard::clean($parsed['suggestion'], $allowedAmounts, $coveredPlaces);
        $removed = $g['price_hits'] + $g['coverage_hits'];
        $parsed['suggestion'] = $g['reply'];
        $parsed['removed'] = $removed;
        if ($removed > 0) {
            $parsed['check'] = trim($parsed['check'] . ' ' . ($g['price_hits'] ? 'A price that is not in the catalogue or the approved knowledge was removed. ' : '')
                . ($g['coverage_hits'] ? 'A coverage claim for a place management has not published as covered was removed.' : ''));
        }

        return $parsed;
    }

    private function record(Tenant $tenant, Conversation $convo, User $user, array $out): ?int
    {
        try {
            if (! Schema::hasTable('sales_coach_suggestions')) {
                return null;
            }

            return (int) SalesCoachSuggestion::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'conversation_id' => $convo->id, 'user_id' => $user->id, 'customer_phone' => (string) $convo->customer_phone,
                'suggestion' => $out['suggestion'], 'why' => $out['why'], 'next_step' => $out['next_step'] ?: null, 'used' => $out['used'],
                'check_note' => $out['check'] ?: null, 'guard_removed' => (int) $out['removed'],
            ])->id;
        } catch (\Throwable $e) {
            report($e);

            return null;               // recording never stops the salesperson getting the suggestion
        }
    }
}
