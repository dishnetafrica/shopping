<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use Illuminate\Http\Request;

/**
 * WhatsApp on the customer record (v4.4) — the V3 customer page grows a 💬 card:
 * the conversation tail, a composer (posts through the PROVEN /papi/chats/send —
 * which also flips agent_active so the bot yields the chat), and an ✨ AI draft
 * button that writes a personalised message FROM the relationship memory
 * (stage / current goal / usual products) for the operator to review and send.
 * The AI never sends anything by itself.
 *
 *   GET  /papi/wa-thread?phone=      last messages + conversation id
 *   POST /papi/wa-ai-draft           {phone, hint?} → {draft}
 */
class CustomerWaController extends Controller
{
    public function thread(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $phone = preg_replace('/[^0-9]/', '', (string) $request->query('phone', ''));
        if ($phone === '') {
            return response()->json(['ok' => false, 'error' => 'phone_required'], 422);
        }

        $msgs = Message::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('customer_phone', $phone)
            ->orderByDesc('id')->limit(12)
            ->get(['direction', 'sender', 'body', 'created_at'])
            ->reverse()->values()
            ->map(fn ($m) => [
                'dir'  => (string) $m->direction,                    // in | out
                'who'  => (string) ($m->sender ?? ''),
                'body' => mb_substr((string) ($m->body ?? ''), 0, 500),
                'at'   => optional($m->created_at)->format('d M H:i'),
            ]);

        $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('customer_phone', $phone)->orderByDesc('id')->first();

        return response()->json([
            'ok' => true,
            'messages' => $msgs,
            'conversation_id' => $c?->id,
            'agent_active' => (bool) ($c->agent_active ?? false),
        ]);
    }

    public function aiDraft(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));
        $hint = trim((string) $request->input('hint', ''));
        if ($phone === '') {
            return response()->json(['ok' => false, 'error' => 'phone_required'], 422);
        }
        if (! (config('openai.api_key') ?: env('OPENAI_API_KEY')) && trim((string) $t->setting('openai_api_key', '')) === '') {
            return response()->json(['ok' => false, 'error' => 'AI is not configured on this server.'], 422);
        }

        // Everything the model needs: who they are (relationship memory), what was last said,
        // and what the operator wants to achieve.
        $profile = '';
        try { $profile = \App\Services\Relationship\ProfileContext::block($t, $phone); } catch (\Throwable $e) {}
        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->first();
        $tail = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)
            ->orderByDesc('id')->limit(8)->get(['direction', 'body'])->reverse()->values()
            ->map(fn ($m) => ($m->direction === 'in' ? 'CUSTOMER: ' : 'US: ') . mb_substr((string) $m->body, 0, 300))
            ->implode("\n");

        $sys = 'You draft ONE short WhatsApp message (2-4 sentences, warm, plain trade English'
            . ($p && trim((string) $p->lang) !== '' ? ', or in ' . $p->lang . ' if that is their language' : '')
            . ') from ' . ($t->name ?? 'our shop') . ' to a specific customer. '
            . 'Personalise it around what THEY are looking for. Never invent prices, discounts or stock — '
            . 'if the operator hint mentions an offer, use exactly that. No greetings like "Dear", no signatures, '
            . 'no placeholder brackets. Output ONLY the message text.'
            . $profile;
        $userMsg = "Recent conversation:\n" . ($tail !== '' ? $tail : '(no recent messages)')
            . "\n\nOperator's goal for this message: " . ($hint !== '' ? $hint : 'a helpful, personalised check-in that moves their current goal forward');

        try {
            $tenantKey = (string) $t->setting('openai_api_key', '');
            $client = $tenantKey !== '' ? \OpenAI::client($tenantKey) : \OpenAI\Laravel\Facades\OpenAI::getFacadeRoot();
            $model = (string) ($t->setting('ai_model', '') ?: env('OPENAI_MODEL', 'gpt-4o-mini'));
            $res = $client->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $userMsg],
                ],
                'max_tokens' => 220,
                'temperature' => 0.7,
            ]);
            $draft = trim((string) ($res->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            \Log::warning('wa-ai-draft failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'AI draft failed — write it by hand this time.'], 502);
        }
        if ($draft === '') {
            return response()->json(['ok' => false, 'error' => 'AI returned nothing — write it by hand this time.'], 502);
        }

        return response()->json(['ok' => true, 'draft' => $draft]);
    }
}
