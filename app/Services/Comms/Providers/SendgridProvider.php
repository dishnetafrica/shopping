<?php

namespace App\Services\Comms\Providers;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use Illuminate\Support\Facades\Http;

/**
 * SendGrid v3 API. config: { api_key, from_email?, from_name? }
 * custom_args.comm_token carries our token into every SendGrid webhook event.
 */
class SendgridProvider implements EmailProvider
{
    public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult
    {
        $cfg = $account->config ?? [];
        if (empty($cfg['api_key'])) return ProviderResult::failed('sendgrid: api_key not configured');

        $to = [['email' => $message->to_email]];
        if ($message->to_name) $to[0]['name'] = $message->to_name;
        $personalization = ['to' => $to, 'custom_args' => ['comm_token' => $message->token]];
        if ($message->cc)  $personalization['cc']  = array_map(fn ($e) => ['email' => $e], array_values(array_filter((array) $message->cc)));
        if ($message->bcc) $personalization['bcc'] = array_map(fn ($e) => ['email' => $e], array_values(array_filter((array) $message->bcc)));

        $content = [];
        if ($message->text_body) $content[] = ['type' => 'text/plain', 'value' => $message->text_body];
        $content[] = ['type' => 'text/html', 'value' => (string) ($message->html_body ?? '')];

        $payload = [
            'personalizations' => [$personalization],
            'from'    => array_filter(['email' => $cfg['from_email'] ?? $from['email'], 'name' => $cfg['from_name'] ?? $from['name']]),
            'subject' => (string) $message->subject,
            'content' => $content,
        ];
        $rt = $message->reply_to ?: ($from['reply_to'] ?? null);
        if ($rt) $payload['reply_to'] = ['email' => $rt];

        $atts = [];
        foreach ((array) $message->attachments as $att) {
            $b64 = ! empty($att['path']) && is_file($att['path']) ? base64_encode(file_get_contents($att['path'])) : ($att['data'] ?? null);
            if ($b64) $atts[] = ['content' => $b64, 'filename' => $att['name'] ?? 'attachment', 'type' => $att['mime'] ?? 'application/octet-stream'];
        }
        if ($atts) $payload['attachments'] = $atts;

        try {
            $res = Http::withToken($cfg['api_key'])->timeout(25)->post('https://api.sendgrid.com/v3/mail/send', $payload);
            if ($res->status() === 202) return ProviderResult::sent($res->header('X-Message-Id'));
            return ProviderResult::failed('sendgrid http '.$res->status().': '.$res->body());
        } catch (\Throwable $e) {
            return ProviderResult::failed('sendgrid: '.$e->getMessage());
        }
    }
}
