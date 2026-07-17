<?php

namespace App\Services\Comms\Providers;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use Illuminate\Support\Facades\Http;

/**
 * Brevo (ex-Sendinblue) transactional API. config: { api_key, from_email?, from_name? }
 * Our token rides in `tags` so Brevo webhook events map back to the message.
 */
class BrevoProvider implements EmailProvider
{
    public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult
    {
        $cfg = $account->config ?? [];
        if (empty($cfg['api_key'])) return ProviderResult::failed('brevo: api_key not configured');

        $payload = [
            'sender'      => array_filter(['email' => $cfg['from_email'] ?? $from['email'], 'name' => $cfg['from_name'] ?? $from['name']]),
            'to'          => [array_filter(['email' => $message->to_email, 'name' => $message->to_name])],
            'subject'     => (string) $message->subject,
            'htmlContent' => (string) ($message->html_body ?? ''),
            'tags'        => ['comm-'.$message->token],
        ];
        if ($message->text_body) $payload['textContent'] = $message->text_body;
        $rt = $message->reply_to ?: ($from['reply_to'] ?? null);
        if ($rt) $payload['replyTo'] = ['email' => $rt];
        if ($message->cc)  $payload['cc']  = array_map(fn ($e) => ['email' => $e], array_values(array_filter((array) $message->cc)));
        if ($message->bcc) $payload['bcc'] = array_map(fn ($e) => ['email' => $e], array_values(array_filter((array) $message->bcc)));

        $atts = [];
        foreach ((array) $message->attachments as $att) {
            $b64 = ! empty($att['path']) && is_file($att['path']) ? base64_encode(file_get_contents($att['path'])) : ($att['data'] ?? null);
            if ($b64) $atts[] = ['name' => $att['name'] ?? 'attachment', 'content' => $b64];
        }
        if ($atts) $payload['attachment'] = $atts;

        try {
            $res = Http::withHeaders(['api-key' => $cfg['api_key'], 'Accept' => 'application/json'])
                ->timeout(25)->post('https://api.brevo.com/v3/smtp/email', $payload);
            if ($res->successful()) return ProviderResult::sent($res->json('messageId'));
            return ProviderResult::failed('brevo http '.$res->status().': '.$res->body());
        } catch (\Throwable $e) {
            return ProviderResult::failed('brevo: '.$e->getMessage());
        }
    }
}
