<?php
namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Tenant;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * Sales / marketing auto-reply for CloudBSS's OWN WhatsApp line (a tenant
 * marked bot_kind='marketing'). Answers questions about CloudBSS, quotes
 * pricing, pushes the free trial, and offers a human hand-off. Uses OpenAI
 * (same package as BotNlu). Falls back to a helpful canned reply with no key.
 */
class MarketingBrain
{
    /** Sales number / link customers are pushed toward. */
    protected function salesNumber(): string
    {
        return preg_replace('/[^0-9]/', '', (string) config('marketing.whatsapp', ''));
    }

    public function respond(Tenant $tenant, Conversation $convo, string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        // Keep a short rolling history in the conversation state.
        $state   = is_array($convo->state) ? $convo->state : [];
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];
        $history[] = ['role' => 'user', 'content' => $text];
        $history = array_slice($history, -8);   // last few turns only

        $reply = $this->llmReply($history) ?: $this->fallback($text);

        $history[] = ['role' => 'assistant', 'content' => $reply];
        $state['history'] = array_slice($history, -8);
        $convo->state = $state;
        $convo->save();

        return $reply;
    }

    protected function llmReply(array $history): string
    {
        $key = config('openai.api_key') ?: env('OPENAI_API_KEY');
        if (! $key) return '';

        $messages = array_merge([['role' => 'system', 'content' => $this->systemPrompt()]], $history);

        try {
            $resp = OpenAI::chat()->create([
                'model'       => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'temperature' => 0.5,
                'max_tokens'  => 260,
                'messages'    => $messages,
            ]);
            return trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function systemPrompt(): string
    {
        return <<<SYS
You are the friendly sales assistant for CloudBSS, a WhatsApp commerce platform for businesses in Uganda
(supermarkets, pharmacies, restaurants, hardware, bakeries, boutiques).

What CloudBSS does: turns a business's WhatsApp into an online store — an AI assistant takes orders, prices them
and confirms; orders land in one dashboard; the shop sends a rider and the customer tracks delivery. No app for
customers. Works on the business's existing WhatsApp number. Setup takes about 30 minutes and we help personally.

Plans (UGX):
- Free: up to 30 orders/month, AI ordering, 1 staff login. Good for trying it.
- Starter: UGX 75,000/month (~$20) — unlimited orders, order confirmations, 2 logins.
- Pro: UGX 185,000/month (~$50) — everything: counter sales (POS), riders + live tracking, reports, multi-branch,
  unlimited logins, and the official WhatsApp Business API option.
New businesses get a 30-day free trial of all features. No card needed to start.

Your job:
- Answer questions clearly and briefly in a warm, professional WhatsApp tone (short paragraphs, occasional emoji, no walls of text).
- Always nudge toward starting the FREE trial or booking a quick setup.
- If asked for things you don't know (exact contracts, custom deals, technical edge cases) or if the person wants
  to speak to a person, say a team member will follow up shortly and ask for their business name and town.
- Never invent customer numbers, fake testimonials, or features that don't exist above.
- Keep replies under ~70 words unless the person asks for detail.
SYS;
    }

    protected function fallback(string $text): string
    {
        $t = strtolower($text);
        if (preg_match('/price|cost|how much|charge|plan/', $t)) {
            return "CloudBSS plans (UGX): *Free* (30 orders/mo), *Starter* 75,000/mo (unlimited), *Pro* 185,000/mo (everything — POS, riders, tracking, reports). New shops get a 30-day free trial. Want me to start your free trial?";
        }
        if (preg_match('/\b(hi|hello|hey|start|menu|hallo)\b/', $t)) {
            return "Hi 👋 Welcome to CloudBSS — we turn your WhatsApp into an online store that takes orders 24/7. Would you like to start a free trial, or hear how it works?";
        }
        return "Thanks for your message! 🙏 CloudBSS turns your WhatsApp into a store that takes orders, tracks deliveries and serves customers 24/7 — no app needed. A team member will follow up shortly. What's your business name and town?";
    }
}
