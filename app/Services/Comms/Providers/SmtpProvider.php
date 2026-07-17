<?php

namespace App\Services\Comms\Providers;

use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Services\Comms\Contracts\EmailProvider;
use App\Services\Comms\ProviderResult;
use Illuminate\Support\Facades\Mail;

/**
 * Generic SMTP transport. Covers plain SMTP, Microsoft 365 (smtp.office365.com:587),
 * Gmail Workspace (smtp.gmail.com:587 + app password) and Amazon SES SMTP
 * (email-smtp.<region>.amazonaws.com:587) — one driver, four spec providers.
 *
 * config: { host, port, username, password, encryption: tls|ssl|none,
 *           from_email?, from_name? (verified-sender override) }
 */
class SmtpProvider implements EmailProvider
{
    public function send(CommProvider $account, CommMessage $message, array $from): ProviderResult
    {
        $cfg = $account->config ?? [];
        if (empty($cfg['host'])) return ProviderResult::failed('smtp: host not configured');

        try {
            $enc = $cfg['encryption'] ?? 'tls';
            $mailer = Mail::build([
                'transport'  => 'smtp',
                'host'       => $cfg['host'],
                'port'       => (int) ($cfg['port'] ?? 587),
                'username'   => $cfg['username'] ?? null,
                'password'   => $cfg['password'] ?? null,
                'encryption' => $enc === 'none' ? null : $enc,
                'timeout'    => 20,
            ]);

            $fromEmail = $cfg['from_email'] ?? $from['email'];
            $fromName  = $cfg['from_name']  ?? $from['name'];
            $html      = $message->html_body ?? nl2br(e((string) $message->text_body));

            $sent = $mailer->html($html, function ($m) use ($message, $from, $fromEmail, $fromName) {
                $m->to($message->to_email, $message->to_name ?: null)
                  ->subject((string) $message->subject)
                  ->from($fromEmail, $fromName ?: null);
                if (! empty($from['reply_to']) || $message->reply_to) {
                    $m->replyTo($message->reply_to ?: $from['reply_to']);
                }
                foreach ((array) $message->cc  as $cc)  { if ($cc)  $m->cc($cc); }
                foreach ((array) $message->bcc as $bcc) { if ($bcc) $m->bcc($bcc); }
                foreach ((array) $message->attachments as $att) {
                    if (! empty($att['path']) && is_file($att['path'])) {
                        $m->attach($att['path'], ['as' => $att['name'] ?? basename($att['path'])]);
                    } elseif (! empty($att['data'])) {
                        $m->attachData(base64_decode($att['data']), $att['name'] ?? 'attachment', ['mime' => $att['mime'] ?? 'application/octet-stream']);
                    }
                }
            });

            $id = null;
            if ($sent && method_exists($sent, 'getMessageId')) {
                $id = $sent->getMessageId();
            }
            return ProviderResult::sent($id);
        } catch (\Throwable $e) {
            return ProviderResult::failed('smtp: '.$e->getMessage());
        }
    }
}
