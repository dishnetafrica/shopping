<?php

namespace App\Services\Comms;

use App\Models\CommTemplate;
use App\Models\Tenant;

/**
 * Renders {{var}} placeholders in subject / html / text bodies.
 *
 * Rules:
 *  - Both {{var}} and {{ var }} spellings work.
 *  - Values are HTML-escaped inside html bodies UNLESS the key ends in `_html`
 *    (lets a module inject a pre-built table, e.g. {{lines_html}}).
 *  - Every render gets tenant defaults for free: business_name, today, year,
 *    plus {{unsubscribe_url}} (filled by the dispatcher once the token exists).
 */
class TemplateRenderer
{
    public function defaults(Tenant $tenant): array
    {
        return [
            'business_name' => (string) ($tenant->setting('comms_email.from_name') ?: $tenant->name),
            'today'         => now()->timezone('Africa/Kampala')->format('d M Y'),
            'year'          => now()->format('Y'),
        ];
    }

    /** @return array{subject:?string, html:?string, text:?string} */
    public function renderTemplate(Tenant $tenant, CommTemplate $tpl, array $vars): array
    {
        $vars = array_merge($this->defaults($tenant), $vars);

        return [
            'subject' => $tpl->subject   !== null ? $this->renderString($tpl->subject, $vars, false) : null,
            'html'    => $tpl->html_body !== null ? $this->renderString($tpl->html_body, $vars, true) : null,
            'text'    => $tpl->text_body !== null ? $this->renderString($tpl->text_body, $vars, false) : null,
        ];
    }

    public function renderString(string $body, array $vars, bool $htmlContext): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($vars, $htmlContext) {
            $key = $m[1];
            // unsubscribe_url is filled LATER (EmailService, once the message token
            // exists) — pass the placeholder through untouched instead of wiping it.
            if ($key === 'unsubscribe_url' && ! array_key_exists('unsubscribe_url', $vars)) {
                return '{{unsubscribe_url}}';
            }
            $val = $vars[$key] ?? data_get($vars, $key);
            if ($val === null) return '';                    // unknown vars render empty, never literal
            $val = is_scalar($val) ? (string) $val : json_encode($val);
            if ($htmlContext && ! str_ends_with($key, '_html') && $key !== 'unsubscribe_url') {
                $val = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            }
            return $val;
        }, $body);
    }
}
