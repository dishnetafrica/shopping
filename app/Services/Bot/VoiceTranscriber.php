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
            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
