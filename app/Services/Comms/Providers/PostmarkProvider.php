<?php

namespace App\Services\Comms\Providers;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use Illuminate\Support\Facades\Http;

/**
 * Postmark API. config: { server_token, message_stream?, from_email?, from_name? }
 * Metadata.comm_token flows back on every Postmark webhook.
 */
class PostmarkProvider implements EmailProvider
{
    public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult
    {
        $cfg = $account->config ?? [];
        if (empty($cfg['server_token'])) return ProviderResult::failed('postmark: server_token not configured');

        $fromEmail = $cfg['from_email'] ?? $from['email'];
        $fromName  = $cfg['from_name']  ?? $from['name'];

        $payload = [
            'From'     => $fromName ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'To'       => $message->to_email,
            'Subject'  => (string) $message->subject,
            'HtmlBody' => (string) ($message->html_body ?? ''),
            'Metadata' => ['comm_token' => $message->token],
            'MessageStream' => $cfg['message_stream'] ?? 'outbound',
        ];
        if ($message->text_body) $payload['TextBody'] = $message->text_body;
        $rt = $message->reply_to ?: ($from['reply_to'] ?? null);
        if ($rt) $payload['ReplyTo'] = $rt;
        if ($message->cc)  $payload['Cc']  = implode(',', array_filter((array) $message->cc));
        if ($message->bcc) $payload['Bcc'] = implode(',', array_filter((array) $message->bcc));

        $atts = [];
        foreach ((array) $message->attachments as $att) {
            $b64 = ! empty($att['path']) && is_file($att['path']) ? base64_encode(file_get_contents($att['path'])) : ($att['data'] ?? null);
            if ($b64) $atts[] = ['Name' => $att['name'] ?? 'attachment', 'Content' => $b64, 'ContentType' => $att['mime'] ?? 'application/octet-stream'];
        }
        if ($atts) $payload['Attachments'] = $atts;

        try {
            $res = Http::withHeaders(['X-Postmark-Server-Token' => $cfg['server_token'], 'Accept' => 'application/json'])
                ->timeout(25)->post('https://api.postmarkapp.com/email', $payload);
            if ($res->successful() && (int) $res->json('ErrorCode', 0) === 0) {
                return ProviderResult::sent($res->json('MessageID'));
            }
            return ProviderResult::failed('postmark http '.$res->status().': '.$res->body());
        } catch (\Throwable $e) {
            return ProviderResult::failed('postmark: '.$e->getMessage());
        }
    }
}
