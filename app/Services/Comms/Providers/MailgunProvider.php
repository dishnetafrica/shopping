<?php

namespace App\Services\Comms\Providers;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use Illuminate\Support\Facades\Http;

/**
 * Mailgun HTTP API. config: { domain, api_key, region: us|eu, from_email?, from_name? }
 * Sends v:comm-token so webhook events map straight back to comm_messages.token.
 */
class MailgunProvider implements EmailProvider
{
    public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult
    {
        $cfg = $account->config ?? [];
        if (empty($cfg['domain']) || empty($cfg['api_key'])) {
            return ProviderResult::failed('mailgun: domain/api_key not configured');
        }

        $base = (($cfg['region'] ?? 'us') === 'eu') ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $fromEmail = $cfg['from_email'] ?? $from['email'];
        $fromName  = $cfg['from_name']  ?? $from['name'];

        $form = [
            'from'    => $fromName ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'to'      => $message->to_name ? sprintf('%s <%s>', $message->to_name, $message->to_email) : $message->to_email,
            'subject' => (string) $message->subject,
            'html'    => (string) ($message->html_body ?? ''),
            'v:comm-token' => $message->token,
        ];
        if ($message->text_body) $form['text'] = $message->text_body;
        $rt = $message->reply_to ?: ($from['reply_to'] ?? null);
        if ($rt) $form['h:Reply-To'] = $rt;
        foreach ((array) $message->cc  as $cc)  { if ($cc)  $form['cc']  = isset($form['cc'])  ? $form['cc'].','.$cc   : $cc; }
        foreach ((array) $message->bcc as $bcc) { if ($bcc) $form['bcc'] = isset($form['bcc']) ? $form['bcc'].','.$bcc : $bcc; }

        try {
            $req = Http::withBasicAuth('api', $cfg['api_key'])->timeout(25)->asMultipart();
            foreach ($form as $k => $v) $req = $req->attach($k, $v);
            foreach ((array) $message->attachments as $i => $att) {
                $bytes = ! empty($att['path']) && is_file($att['path']) ? file_get_contents($att['path'])
                       : (! empty($att['data']) ? base64_decode($att['data']) : null);
                if ($bytes !== null) $req = $req->attach('attachment', $bytes, $att['name'] ?? ('file'.$i));
            }
            $res = $req->post($base.'/v3/'.$cfg['domain'].'/messages');
            if ($res->successful()) return ProviderResult::sent($res->json('id'));
            return ProviderResult::failed('mailgun http '.$res->status().': '.$res->body());
        } catch (\Throwable $e) {
            return ProviderResult::failed('mailgun: '.$e->getMessage());
        }
    }
}
