<?php

namespace App\Services\Comms;

use App\Jobs\SendCommEmail;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\CommTemplate;
use App\Models\Tenant;

/**
 * The ONE front door for email in CloudBSS. No module talks SMTP — modules call
 * this (directly or via App\Support\Comms) and everything else (rendering,
 * suppression, queueing, failover, tracking, audit) happens behind it.
 *
 *   app(EmailService::class)->queueTemplate($tenant, 'invoice', 'a@b.com', [
 *       'invoice_number' => 'INV-0042', 'amount' => '$120.00',
 *   ], ['attachments' => [['name' => 'INV-0042.pdf', 'data' => $b64]]]);
 *
 * NOTHING sends synchronously. Every message becomes a comm_messages row
 * (status=pending) and is delivered by the queue worker / minute sweeper.
 */
class EmailService
{
    public function __construct(protected TemplateRenderer $renderer)
    {
    }

    /**
     * Queue a template-based email.
     * $opts: to_name, cc[], bcc[], reply_to, locale, attachments[], scheduled_at,
     *        related_type, related_id, meta[], created_by, subject (override)
     */
    public function queueTemplate(Tenant $tenant, string $templateKey, string $toEmail, array $vars = [], array $opts = []): CommMessage
    {
        $tpl = CommTemplate::resolve($tenant->id, $templateKey, $opts['locale'] ?? 'en');
        if (! $tpl) {
            // No template — fail loud in the log, not silently. The row records why.
            return $this->persist($tenant, $toEmail, [
                'subject'  => $opts['subject'] ?? $templateKey,
                'html'     => null, 'text' => null,
            ], $vars, array_merge($opts, [
                'template_key' => $templateKey,
                'force_status' => CommMessage::ST_FAILED,
                'error'        => "template '{$templateKey}' not found for tenant",
            ]));
        }

        $rendered = $this->renderer->renderTemplate($tenant, $tpl, $vars);
        if (! empty($opts['subject'])) $rendered['subject'] = $opts['subject'];

        return $this->persist($tenant, $toEmail, $rendered, $vars, array_merge($opts, ['template_key' => $templateKey]));
    }

    /** Queue a raw email (subject + html and/or text already built by the caller). */
    public function queueRaw(Tenant $tenant, string $toEmail, string $subject, ?string $html, ?string $text = null, array $opts = []): CommMessage
    {
        return $this->persist($tenant, $toEmail, ['subject' => $subject, 'html' => $html, 'text' => $text], [], $opts);
    }

    protected function persist(Tenant $tenant, string $toEmail, array $rendered, array $vars, array $opts): CommMessage
    {
        $toEmail = mb_strtolower(trim($toEmail));

        $m = new CommMessage();
        $m->tenant_id    = $tenant->id;
        $m->channel      = 'email';
        $m->to_email     = $toEmail;
        $m->to_name      = $opts['to_name'] ?? null;
        $m->cc           = $opts['cc'] ?? null;
        $m->bcc          = $opts['bcc'] ?? null;
        $m->reply_to     = $opts['reply_to'] ?? null;
        $m->subject      = $rendered['subject'] ?? null;
        $m->text_body    = $rendered['text'] ?? null;
        $m->template_key = $opts['template_key'] ?? null;
        $m->vars         = $vars ?: null;
        $m->attachments  = $opts['attachments'] ?? null;
        $m->scheduled_at = $opts['scheduled_at'] ?? null;
        $m->related_type = $opts['related_type'] ?? null;
        $m->related_id   = $opts['related_id'] ?? null;
        $m->meta         = $opts['meta'] ?? null;
        $m->created_by   = $opts['created_by'] ?? null;
        $m->max_attempts = (int) ($opts['max_attempts'] ?? 5);
        $m->save();                               // token generated here

        // Finalise HTML now that the token exists: unsubscribe link, footer, open pixel.
        if (! empty($rendered['html'])) {
            $m->html_body = $this->finaliseHtml($tenant, $m, $rendered['html']);
        }

        // Hard gates AFTER persist so the audit row exists either way.
        if (! empty($opts['force_status'])) {
            $m->status     = $opts['force_status'];
            $m->last_error = $opts['error'] ?? null;
            $m->save();
            return $m;
        }
        if (! filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $m->status = CommMessage::ST_FAILED;
            $m->last_error = 'invalid recipient address';
            $m->save();
            return $m;
        }
        if (CommSuppression::isSuppressed($tenant->id, $toEmail)) {
            $m->status = CommMessage::ST_CANCELLED;
            $m->last_error = 'recipient is on the suppression list';
            $m->save();
            return $m;
        }
        $m->save();

        // Immediate messages get a job now; scheduled ones are picked up by the sweeper.
        if (! $m->scheduled_at || ! $m->scheduled_at->isFuture()) {
            SendCommEmail::dispatch($m->id);
        }

        return $m;
    }

    protected function finaliseHtml(Tenant $tenant, CommMessage $m, string $html): string
    {
        $base  = rtrim((string) config('app.url'), '/');
        $unsub = $base.'/c/e/u/'.$m->token;
        $html  = str_replace(['{{unsubscribe_url}}', '{{ unsubscribe_url }}'], $unsub, $html);

        $footer = trim((string) $tenant->setting('comms_email.footer_text', ''));
        $bits   = '';
        if ($footer !== '') {
            $bits .= '<div style="margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280">'
                   . nl2br(e($footer)).'</div>';
        }
        // Open-tracking pixel (1×1). Marketing-law unsubscribe stays template-driven
        // via {{unsubscribe_url}}; transactional templates simply omit it.
        $bits .= '<img src="'.$base.'/c/e/o/'.$m->token.'.gif" width="1" height="1" alt="" style="display:none">';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $bits.'</body>', $html, 1);
        }
        return $html.$bits;
    }
}
