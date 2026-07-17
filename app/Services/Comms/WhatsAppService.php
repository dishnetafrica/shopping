<?php

namespace App\Services\Comms;

use App\Jobs\SendCommWhatsApp;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\CommTemplate;
use App\Models\Tenant;

/**
 * M3 — WhatsApp as a Communication Center transport.
 *
 * SAME spine as email: every send = one comm_messages row (channel='whatsapp',
 * `to_email` holds the E.164 phone — the column is the generic recipient
 * address across channels). Rendering, suppression, backoff, panel visibility
 * all reuse the M1 machinery.
 *
 * Template resolution: comm_templates channel='whatsapp' text_body first; if a
 * tenant hasn't customised one, the EMAIL template's text_body is the fallback
 * — one place to write the plain-text wording for both channels.
 *
 * IMPORTANT: this service only QUEUES. Actual sending (WhatsAppDispatcher)
 * wraps the frozen WhatsAppManager — read-only use of its public API, exactly
 * like NotifyCustomerOrderReceived does. No bot/gateway file is modified.
 */
class WhatsAppService
{
    public function __construct(protected TemplateRenderer $renderer)
    {
    }

    public function queueTemplate(Tenant $tenant, string $templateKey, string $phone, array $vars = [], array $opts = []): CommMessage
    {
        $text = null;
        $tpl  = CommTemplate::resolve($tenant->id, $templateKey, $opts['locale'] ?? 'en', 'whatsapp');
        if ($tpl && $tpl->text_body !== null) {
            $text = $tpl->text_body;
        } else {
            $mailTpl = CommTemplate::resolve($tenant->id, $templateKey, $opts['locale'] ?? 'en', 'email');
            if ($mailTpl && $mailTpl->text_body !== null) $text = $mailTpl->text_body;
        }

        if ($text !== null) {
            $vars = array_merge($this->renderer->defaults($tenant), $vars);
            $text = $this->renderer->renderString($text, $vars, false);
        }

        return $this->persist($tenant, $phone, $text, $vars, array_merge($opts, [
            'template_key' => $templateKey,
            'missing'      => $text === null,
        ]));
    }

    /** Queue a raw, already-built WhatsApp text. */
    public function queueRaw(Tenant $tenant, string $phone, string $text, array $opts = []): CommMessage
    {
        return $this->persist($tenant, $phone, $text, [], $opts);
    }

    protected function persist(Tenant $tenant, string $phone, ?string $text, array $vars, array $opts): CommMessage
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?: '';

        $m = new CommMessage();
        $m->tenant_id    = $tenant->id;
        $m->channel      = 'whatsapp';
        $m->to_email     = $phone;                       // generic recipient address
        $m->to_name      = $opts['to_name'] ?? null;
        $m->text_body    = $text;
        $m->template_key = $opts['template_key'] ?? null;
        $m->vars         = $vars ?: null;
        $m->scheduled_at = $opts['scheduled_at'] ?? null;
        $m->related_type = $opts['related_type'] ?? null;
        $m->related_id   = $opts['related_id'] ?? null;
        $m->meta         = $opts['meta'] ?? null;
        $m->created_by   = $opts['created_by'] ?? null;
        $m->max_attempts = (int) ($opts['max_attempts'] ?? 5);
        $m->save();

        if (! empty($opts['missing'])) {
            $m->status = CommMessage::ST_FAILED;
            $m->last_error = "no whatsapp/email text template for '".($opts['template_key'] ?? '?')."'";
            $m->save();
            return $m;
        }
        if ($phone === '' || strlen($phone) < 7) {
            $m->status = CommMessage::ST_FAILED;
            $m->last_error = 'invalid recipient phone';
            $m->save();
            return $m;
        }
        if (CommSuppression::isSuppressed($tenant->id, $phone, 'whatsapp')) {
            $m->status = CommMessage::ST_CANCELLED;
            $m->last_error = 'recipient is on the suppression list';
            $m->save();
            return $m;
        }
        if (! $tenant->sendInstance()) {
            $m->status = CommMessage::ST_FAILED;
            $m->last_error = 'tenant has no WhatsApp instance connected';
            $m->save();
            return $m;
        }

        if (! $m->scheduled_at || ! $m->scheduled_at->isFuture()) {
            SendCommWhatsApp::dispatch($m->id);
        }

        return $m;
    }
}
