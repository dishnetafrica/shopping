<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Http;

/**
 * Chat translation for the Juba reality: customers write (Juba) Arabic, the team
 * reads English. Mirrors the stack's existing AI pattern (VoiceTranscriber/BotNlu):
 * config('openai.api_key'), gpt-4o-mini, 8s timeout, NEVER throws — any failure
 * returns null and the UI degrades gracefully.
 */
class ChatTranslator
{
    public function enabled(): bool
    {
        return trim((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''))) !== '';
    }

    /** Customer message → natural English (returns the text unchanged if already English). */
    public function toEnglish(string $text): ?string
    {
        return $this->run(
            'You translate WhatsApp messages from customers in South Sudan (often Juba Arabic, '
            .'sometimes mixed with English) into natural English for shop staff. Reply with ONLY '
            .'the translation — no quotes, no explanations. If the message is already English, '
            .'reply with it unchanged.',
            $text
        );
    }

    /** Staff reply → simple Arabic a Juba customer understands. */
    public function toArabic(string $text): ?string
    {
        return $this->run(
            'You translate a shop\'s WhatsApp reply into simple, polite Arabic that a customer '
            .'in Juba, South Sudan will easily understand. Keep numbers, prices and product '
            .'names as-is. Reply with ONLY the translation.',
            $text
        );
    }

    /** Does the text contain Arabic script? */
    public static function hasArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /**
     * Auto-translate one inbound message and cache it on the row (idempotent — the
     * cache makes repeat calls free). Returns the English, or null on any failure.
     */
    public function translateAndCache(\App\Models\Message $m): ?string
    {
        try {
            $meta = (array) ($m->meta ?? []);
            if (! empty($meta['tr_en'])) return (string) $meta['tr_en'];
            if (! self::hasArabic((string) $m->body)) return null;

            $en = $this->toEnglish((string) $m->body);
            if ($en === null) return null;

            $meta['tr_en'] = $en;
            $m->meta = $meta;
            $m->save();

            return $en;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function run(string $system, string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || ! $this->enabled()) return null;

        try {
            $resp = Http::withToken((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', '')))
                ->timeout(8)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => 'gpt-4o-mini',
                    'temperature' => 0.2,
                    'messages'    => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => mb_substr($text, 0, 2000)],
                    ],
                ]);
            if (! $resp->successful()) return null;
            $out = $resp->json('choices.0.message.content');

            return is_string($out) && trim($out) !== '' ? trim($out) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
