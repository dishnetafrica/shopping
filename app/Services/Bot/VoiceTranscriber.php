<?php
namespace App\Services\Bot;

use Illuminate\Support\Facades\Http;

/**
 * Transcribes a WhatsApp voice note (ogg/opus) to text via OpenAI audio transcription,
 * so a spoken order flows through the same pipeline as a typed one. Auto-detects the
 * spoken language (Gujarati / Hindi / English). Returns null on any failure so the caller
 * can fall back to acknowledging the note and handing the chat to a human.
 */
class VoiceTranscriber
{
    public function enabled(): bool
    {
        return (bool) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
    }

    public function transcribe(string $b64): ?string
    {
        if (! $this->enabled() || $b64 === '') return null;
        $key   = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
        $model = (string) env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1');

        $bytes = base64_decode($b64, true);
        if ($bytes === false || strlen($bytes) < 200) return null;   // empty / not audio

        try {
            $resp = Http::withToken($key)->timeout(40)
                ->attach('file', $bytes, 'voice.ogg')
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'  => $model,
                    // Bias toward the shop's vocabulary; whisper still auto-detects the language.
                    'prompt' => 'Grocery / farsan order in Gujarati, Hindi or English. '
                              . 'Items: thali, tiffin, sev, gathiya, fafda, jalebi, khaman, dhokla, '
                              . 'samosa, paneer, kaju, almond. Quantities in kg, gm, packet, pcs, thali.',
                ]);
            if (! $resp->successful()) return null;
            $text = trim((string) $resp->json('text'));
            if ($text === '') return null;
            return $this->romanise($text);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whisper returns Gujarati/Hindi speech in native script, which the romanised (Gujlish)
     * dictionaries cannot match. If the transcript contains Gujarati/Devanagari characters,
     * transliterate it to the Latin spelling customers type. Falls back to the raw transcript
     * on any failure, so we never lose the order.
     */
    private function romanise(string $text): string
    {
        if (! preg_match('/[\x{0900}-\x{097F}\x{0A80}-\x{0AFF}]/u', $text)) return $text; // already Latin
        $key = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY'));
        if ($key === '') return $text;
        $model = (string) env('OPENAI_TEXT_MODEL', 'gpt-4o-mini');
        $sys = 'You convert a spoken grocery/farsan order into romanised Latin text (Gujlish) a shop bot can parse. '
             . 'Transliterate Gujarati/Hindi to the English letters customers type (e.g. થાળી->thali, સેવ->sev, ગાંઠિયા->gathiya, બે->be/2). '
             . 'Keep product names, quantities (kg, gm, packet, pcs, thali) and times. Do NOT translate product names to English. '
             . 'Output ONLY the order text — no quotes, no commentary.';
        try {
            $resp = Http::withToken($key)->timeout(20)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model, 'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $text],
                ],
            ]);
            if (! $resp->successful()) return $text;
            $out = trim((string) $resp->json('choices.0.message.content'));
            return $out !== '' ? $out : $text;
        } catch (\Throwable $e) {
            return $text;
        }
    }
}
