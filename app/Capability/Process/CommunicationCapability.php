<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\CommCampaign;
use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Models\CommSuppression;
use App\Models\CommTemplate;
use App\Services\Comms\CommCenter;
use Illuminate\Support\Carbon;

/**
 * Communication — the v3 face of the classic /panel/email-center (Migration plan G2).
 *
 * Reads the Comms models directly (allowed for a Process capability); EVERY write goes
 * through the proven /papi/comms/* endpoints — including the five thin adapters added
 * for flat v3 forms (messages/retry, messages/cancel, routes/save-simple,
 * suppressions/add, suppressions/remove). The engine (EmailService, dispatchers,
 * CampaignService, go-live gate) is untouched. Also closes the M1 gap: the
 * suppression list finally has a management screen.
 */
final class CommunicationCapability implements ProcessCapability
{
    private const QUEUE_ROWS = 40;
    private const SUPP_ROWS  = 40;

    public function key(): string { return 'communication'; }

    public function permission(): string { return 'menu:settings'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        // Incident 2026-07-18: opening this workspace before `php artisan migrate`
        // has created the comm_* tables must EXPLAIN, not 500.
        if (! $this->backendReady()) {
            return (new WorkspacePayload)
                ->object(['type' => 'process:communication', 'title' => 'Email Center', 'subtitle' => 'Backend not installed yet', 'status' => 'inactive'])
                ->headline('SETUP', 'Email Center', 'warn')
                ->tabSections(['setup' => [[
                    'kind' => 'dl', 'title' => 'Finish installation', 'rows' => [
                        ['1. Create the tables', 'php artisan migrate'],
                        ['2. Install templates', 'php artisan comms:seed-templates '.$ctx->tenantId()],
                        ['Then', 'refresh this screen — the queue, providers, routing, campaigns and suppressions appear here.'],
                    ],
                ]]])
                ->tabs([['key' => 'setup', 'label' => 'Setup']])
                ->meta(['capability' => 'communication', 'ttl' => 5, 'permissions' => ['menu:settings']]);
        }

        $t = $ctx->tenant;
        $tid = $ctx->tenantId();
        $isOwner = $ctx->can('menu:settings');
        $live = (bool) $t->setting('comms_email_live', false);
        $fromEmail = (string) $t->setting('comms_email.from_email', '');
        $fromName  = (string) $t->setting('comms_email.from_name', '');

        $msgs = fn () => CommMessage::withoutGlobalScope('tenant')->where('tenant_id', $tid);

        // ---- KPIs ----------------------------------------------------------
        $sent24   = (clone $msgs())->where('status', 'sent')->where('sent_at', '>=', now()->subDay())->count();
        $failed   = (clone $msgs())->where('status', 'failed')->count();
        $queued   = (clone $msgs())->whereIn('status', ['pending', 'retry', 'sending'])->count();
        $suppTot  = CommSuppression::withoutGlobalScope('tenant')->where('tenant_id', $tid)->count();

        // ---- Queue tab -----------------------------------------------------
        $recent = (clone $msgs())->orderByDesc('id')->limit(self::QUEUE_ROWS)->get();
        $queueRows = [];
        $retryable = []; $cancellable = [];
        foreach ($recent as $m) {
            $tone = ['sent' => 'ok', 'failed' => 'danger', 'pending' => 'info', 'retry' => 'warn', 'sending' => 'warn', 'cancelled' => 'default'][$m->status] ?? 'default';
            $queueRows[] = [
                Carbon::parse($m->created_at)->format('d M H:i'),
                (string) $m->to_email,
                strtoupper((string) $m->channel),
                (string) ($m->template_key ?: '—'),
                ['value' => ucfirst((string) $m->status), 'tone' => $tone],
                $m->attempts.'/'.$m->max_attempts,
                mb_substr((string) ($m->last_error ?: ''), 0, 60),
            ];
            $label = $m->id.' — '.$m->to_email.($m->last_error ? ' · '.mb_substr($m->last_error, 0, 30) : '');
            if (in_array($m->status, ['failed', 'cancelled'], true)) $retryable[] = $label;
            if (in_array($m->status, ['pending', 'retry'], true))    $cancellable[] = $label;
        }
        $queueCard = ['kind' => 'table', 'title' => 'Recent messages', 'sub' => 'newest '.count($queueRows).' · all channels', 'searchable' => true,
            'columns' => [['label' => 'When'], ['label' => 'To'], ['label' => 'Ch'], ['label' => 'Template'], ['label' => 'Status'], ['label' => 'Tries', 'align' => 'right'], ['label' => 'Last error']],
            'rows' => $queueRows, 'empty' => 'No messages yet — turn routing on and the platform\'s emails/WhatsApps appear here.', 'pageSize' => 15];

        // ---- Templates tab -------------------------------------------------
        $tpls = CommTemplate::withoutGlobalScope('tenant')->where('tenant_id', $tid)
            ->orderBy('channel')->orderBy('key')->get();
        $tplRows = $tpls->map(fn ($x) => [
            (string) $x->key, strtoupper((string) $x->channel), (string) $x->locale,
            (string) ($x->subject ?: $x->name),
            ['value' => $x->is_active ? 'Active' : 'Off', 'tone' => $x->is_active ? 'ok' : 'default'],
            Carbon::parse($x->updated_at)->format('d M'),
        ])->all();
        $tplCard = ['kind' => 'table', 'title' => 'Templates', 'sub' => count($tplRows).' installed', 'searchable' => true,
            'columns' => [['label' => 'Key'], ['label' => 'Ch'], ['label' => 'Locale'], ['label' => 'Subject / name'], ['label' => 'State'], ['label' => 'Updated', 'align' => 'right']],
            'rows' => $tplRows, 'empty' => 'No templates — use "Install default templates".', 'pageSize' => 15];

        // ---- Providers tab -------------------------------------------------
        $provs = CommProvider::withoutGlobalScope('tenant')->where('tenant_id', $tid)
            ->orderBy('priority')->get();
        $provRows = $provs->map(fn ($p) => [
            (string) $p->priority, (string) $p->name, strtoupper((string) $p->driver),
            ['value' => $p->is_active ? 'Active' : 'Off', 'tone' => $p->is_active ? 'ok' : 'warn'],
            $p->daily_limit ? number_format($p->daily_limit).'/day' : 'unlimited',
        ])->all();
        $provCard = ['kind' => 'table', 'title' => 'Providers (failover order)', 'sub' => count($provRows).' configured',
            'columns' => [['label' => '#'], ['label' => 'Name'], ['label' => 'Driver'], ['label' => 'State'], ['label' => 'Daily cap', 'align' => 'right']],
            'rows' => $provRows, 'empty' => 'No providers — add SMTP or an API provider, then Test it.'];

        // ---- Routing tab ---------------------------------------------------
        $routes = (array) $t->setting('comms_routes', []);
        $routeRows = [];
        foreach (CommCenter::DEFAULT_ROUTES as $event => $default) {
            $ch = $routes[$event] ?? $default;
            $routeRows[] = [
                str_replace('_', ' ', $event),
                ['value' => in_array('email', $ch, true) ? 'ON' : 'off', 'tone' => in_array('email', $ch, true) ? 'ok' : 'default'],
                ['value' => in_array('whatsapp', $ch, true) ? 'ON' : 'off', 'tone' => in_array('whatsapp', $ch, true) ? 'ok' : 'default'],
            ];
        }
        $routeCard = ['kind' => 'table', 'title' => 'Event routing', 'sub' => 'which channel each platform event uses',
            'columns' => [['label' => 'Event'], ['label' => 'Email'], ['label' => 'WhatsApp']],
            'rows' => $routeRows];

        // ---- Campaigns tab -------------------------------------------------
        $camps = CommCampaign::withoutGlobalScope('tenant')->where('tenant_id', $tid)
            ->orderByDesc('id')->limit(20)->get();

        // Live per-campaign metrics in ONE grouped query over the message spine (sent / delivered /
        // read / skipped), so the list shows real delivery — not a stored counter that can drift.
        $campAgg = [];
        $campIds = $camps->pluck('id')->all();
        if ($campIds) {
            foreach (\Illuminate\Support\Facades\DB::table('comm_messages')->where('tenant_id', $tid)
                ->where('related_type', 'campaign')->whereIn('related_id', $campIds)
                ->selectRaw("related_id, count(*) as total,
                    sum(case when status = 'sent' then 1 else 0 end) as sent,
                    sum(case when delivered_at is not null then 1 else 0 end) as delivered,
                    sum(case when opened_at is not null then 1 else 0 end) as rd,
                    sum(case when replied_at is not null then 1 else 0 end) as replied,
                    sum(case when status = 'failed' then 1 else 0 end) as failed,
                    sum(case when status = 'skipped' then 1 else 0 end) as skipped,
                    sum(case when skip_reason = 'opted_out' then 1 else 0 end) as opted_out")
                ->groupBy('related_id')->get() as $r) {
                $campAgg[(int) $r->related_id] = $r;
            }
        }

        $campRows = [];
        $campActions = [];
        foreach ($camps as $c) {
            $tone = ['draft' => 'default', 'scheduled' => 'info', 'sending' => 'warn', 'paused' => 'warn', 'done' => 'ok', 'cancelled' => 'default'][$c->status] ?? 'default';
            $seg = (array) ($c->segment ?? []);
            $aud = self::audienceLabel($seg);
            $a = $campAgg[$c->id] ?? null;
            $skipped = (int) ($a->skipped ?? 0);

            // Health is DISPLAY ONLY — rates from the live metrics, no automatic action.
            $sent = (int) ($a->sent ?? 0); $failed = (int) ($a->failed ?? 0);
            $delivered = (int) ($a->delivered ?? 0); $optedOut = (int) ($a->opted_out ?? 0);
            $total = (int) ($a->total ?? 0);
            $health = \App\Services\Comms\Marketing\CampaignHealth::assess([
                'sent'          => $sent,
                'failure_rate'  => ($sent + $failed) > 0 ? round($failed / ($sent + $failed) * 100, 1) : 0.0,
                'opt_out_rate'  => $total > 0 ? round($optedOut / $total * 100, 1) : 0.0,
                'delivery_rate' => $sent > 0 ? round($delivered / $sent * 100, 1) : 0.0,
            ], $t);

            $campRows[] = [
                (string) $c->name, strtoupper((string) $c->channel), $aud,
                ['value' => ucfirst((string) $c->status), 'tone' => $tone],
                number_format($sent),
                number_format($delivered),
                number_format((int) ($a->rd ?? 0)),
                number_format((int) ($a->replied ?? 0)),
                ['value' => number_format($skipped), 'tone' => $skipped > 0 ? 'warn' : 'default'],
                ['value' => $health['label'], 'tone' => $health['tone']],
                Carbon::parse($c->created_at)->format('d M'),
            ];
            if ($isOwner && count($campActions) < 12) {
                $ep = '/papi/comms/campaigns/'.$c->id;
                if (in_array($c->status, ['draft', 'scheduled', 'paused'], true)) {
                    // A true dry run — the funnel + a rendered example, sending nothing.
                    $campActions[] = ['key' => 'camp_prev_'.$c->id, 'label' => 'Preview — '.$c->name,
                        'method' => 'POST', 'endpoint' => $ep.'/preview', 'actor' => 'human', 'tone' => 'default'];
                }
                if (in_array($c->status, ['draft', 'paused'], true)) {
                    $campActions[] = ['key' => 'camp_go_'.$c->id, 'label' => ($c->status === 'draft' ? 'Launch — ' : 'Resume — ').$c->name,
                        'method' => 'POST', 'endpoint' => $ep.($c->status === 'draft' ? '/launch' : '/resume'), 'actor' => 'human', 'tone' => 'primary',
                        'confirm' => ($c->status === 'draft' ? 'Launch' : 'Resume').' campaign "'.$c->name.'" now? Phase-1 safety controls (opt-in, opt-out, daily cap, frequency) are enforced automatically.'];
                }
                if (in_array($c->status, ['scheduled', 'sending'], true)) {
                    $campActions[] = ['key' => 'camp_pause_'.$c->id, 'label' => 'Pause — '.$c->name,
                        'method' => 'POST', 'endpoint' => $ep.'/pause', 'actor' => 'human',
                        'confirm' => 'Pause campaign "'.$c->name.'"?'];
                }
            }
        }
        $campCard = ['kind' => 'table', 'title' => 'Campaigns', 'sub' => count($campRows).' most recent · WhatsApp needs explicit opt-in · counts from available order data',
            'columns' => [['label' => 'Name'], ['label' => 'Ch'], ['label' => 'Audience'], ['label' => 'Status'],
                ['label' => 'Sent', 'align' => 'right'], ['label' => 'Delivered', 'align' => 'right'],
                ['label' => 'Read', 'align' => 'right'], ['label' => 'Replies', 'align' => 'right'],
                ['label' => 'Skipped', 'align' => 'right'], ['label' => 'Health'],
                ['label' => 'Created', 'align' => 'right']],
            'rows' => $campRows, 'empty' => 'No campaigns yet — "New campaign" creates a draft you can launch.'];

        // WhatsApp marketing daily budget — a visible ceiling (sent today / limit / remaining) so a
        // campaign can't quietly run past the configured cap.
        $waLimit = \App\Services\Comms\Marketing\DailyLimiter::limit($t);
        $waSent  = \App\Services\Comms\Marketing\DailyLimiter::sentToday($t);
        $waLeft  = \App\Services\Comms\Marketing\DailyLimiter::remaining($t);

        // Consent visibility at a glance — how many customers may be marketed to at all.
        $optInCount = (int) \Illuminate\Support\Facades\DB::table('customer_profiles')->where('tenant_id', $tid)
            ->whereNotNull('marketing_opted_in_at')->whereNull('marketing_opted_out_at')->count();
        $optOutCount = (int) \Illuminate\Support\Facades\DB::table('customer_profiles')->where('tenant_id', $tid)
            ->whereNotNull('marketing_opted_out_at')->count();

        // ---- Suppressions tab (closes the M1 gap) --------------------------
        $supps = CommSuppression::withoutGlobalScope('tenant')->where('tenant_id', $tid)
            ->orderByDesc('id')->limit(self::SUPP_ROWS)->get();
        $suppRows = $supps->map(fn ($x) => [
            strtoupper((string) $x->channel), (string) $x->address,
            (string) ($x->reason ?: '—'), (string) ($x->source ?: '—'),
            Carbon::parse($x->created_at)->format('d M'),
        ])->all();
        $suppCard = ['kind' => 'table', 'title' => 'Suppressed addresses', 'sub' => $suppTot.' total · never contacted by any send path', 'searchable' => true,
            'columns' => [['label' => 'Ch'], ['label' => 'Address'], ['label' => 'Reason'], ['label' => 'Source'], ['label' => 'Added', 'align' => 'right']],
            'rows' => $suppRows, 'empty' => 'Nothing suppressed. Bounces, complaints and unsubscribes land here automatically.', 'pageSize' => 15];

        // ---- Overview + Setup cards ---------------------------------------
        $activeProv = $provs->where('is_active', true)->pluck('name')->implode(' → ') ?: '— none —';
        $statusCard = ['kind' => 'dl', 'title' => 'Status', 'rows' => [
            ['Email Center', $live ? 'LIVE — modules route through the spine' : 'DARK — legacy send paths still active'],
            ['From', $fromEmail !== '' ? trim($fromName.' <'.$fromEmail.'>') : '— not set (required to go live)'],
            ['Provider failover', $activeProv],
            ['Queue', $queued.' queued · '.$failed.' failed'],
            ['Suppression list', $suppTot.' addresses'],
        ]];
        $numbersCard = ['kind' => 'dl', 'title' => 'Last 24 hours', 'rows' => [
            ['Sent', (string) $sent24],
            ['Opens recorded', (string) (clone $msgs())->where('opened_at', '>=', now()->subDay())->count()],
            ['Bounces', (string) (clone $msgs())->where('bounced_at', '>=', now()->subDay())->count()],
        ]];
        $setupCard = ['kind' => 'dl', 'title' => 'Sender & delivery', 'rows' => [
            ['From name', $fromName ?: '—'],
            ['From email', $fromEmail ?: '—'],
            ['Reply-to', (string) $t->setting('comms_email.reply_to', '') ?: '—'],
            ['Footer', (string) $t->setting('comms_email.footer_text', '') ?: '(default)'],
            ['Go-live flag', $live ? 'ON' : 'OFF'],
            ['Webhooks', '/papi/comms/hook/{driver} — per-driver, secret in the classic screen'],
        ]];

        // ---- Owner actions (all queryWrite/POST to proven papi) ------------
        $quick = [];
        if ($isOwner) {
            $quick[] = ['key' => 'edit_sender', 'label' => 'Sender & go-live', 'method' => 'POST', 'endpoint' => '/papi/comms/settings/save', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['from_name' => 'text', 'from_email' => 'text', 'reply_to' => 'text', 'footer_text' => 'text', 'live' => 'enum:0,1'],
                'labels' => ['from_name' => 'From name', 'from_email' => 'From email (required to go live)', 'reply_to' => 'Reply-to', 'footer_text' => 'Footer text', 'live' => 'Email Center live? (1 = yes)'],
                'prefill' => ['from_name' => $fromName, 'from_email' => $fromEmail, 'reply_to' => (string) $t->setting('comms_email.reply_to', ''), 'footer_text' => (string) $t->setting('comms_email.footer_text', ''), 'live' => $live ? '1' : '0']];

            $evFields = []; $evLabels = []; $evPrefill = [];
            foreach (CommCenter::DEFAULT_ROUTES as $event => $default) {
                $ch = $routes[$event] ?? $default;
                $cur = (in_array('email', $ch, true) && in_array('whatsapp', $ch, true)) ? 'both'
                    : (in_array('whatsapp', $ch, true) ? 'whatsapp' : (in_array('email', $ch, true) ? 'email' : 'off'));
                $evFields['ev_'.$event] = 'enum:off,email,whatsapp,both';
                $evLabels['ev_'.$event] = str_replace('_', ' ', ucfirst($event));
                $evPrefill['ev_'.$event] = $cur;
            }
            $quick[] = ['key' => 'edit_routing', 'label' => 'Edit routing', 'method' => 'POST', 'endpoint' => '/papi/comms/routes/save-simple', 'actor' => 'human',
                'schema' => $evFields, 'labels' => $evLabels, 'prefill' => $evPrefill];

            if ($retryable) {
                $quick[] = ['key' => 'retry_msg', 'label' => 'Retry a message', 'method' => 'POST', 'endpoint' => '/papi/comms/messages/retry', 'actor' => 'human',
                    'schema' => ['id' => 'combo:'.implode('|', array_slice($retryable, 0, 30))],
                    'labels' => ['id' => 'Failed / cancelled message']];
            }
            if ($cancellable) {
                $quick[] = ['key' => 'cancel_msg', 'label' => 'Cancel a queued message', 'method' => 'POST', 'endpoint' => '/papi/comms/messages/cancel', 'actor' => 'human',
                    'schema' => ['id' => 'combo:'.implode('|', array_slice($cancellable, 0, 30))],
                    'labels' => ['id' => 'Queued message']];
            }

            $quick[] = ['key' => 'new_template', 'label' => 'New template', 'method' => 'POST', 'endpoint' => '/papi/comms/templates/save', 'actor' => 'human',
                'schema' => ['key' => 'text', 'channel' => 'enum:email,whatsapp', 'locale' => 'text', 'name' => 'text', 'subject' => 'text', 'text_body' => 'textarea', 'html_body' => 'textarea'],
                'labels' => ['key' => 'Key (e.g. invoice)', 'channel' => 'Channel', 'locale' => 'Locale (e.g. en)', 'name' => 'Name', 'subject' => 'Subject ({{vars}} allowed)', 'text_body' => 'Text body ({{vars}} allowed)', 'html_body' => 'HTML body (optional)'],
                'prefill' => ['locale' => 'en', 'channel' => 'email']];
            // Rich editing (round 2): a chooser tile per template opens a PREFILLED form —
            // full body editing with {{vars}}, posting to the same templates/save upsert.
            if ($tpls->count()) {
                $quick[] = ['key' => 'edit_template', 'label' => 'Edit a template', 'actor' => 'human',
                    'chooser' => $tpls->take(30)->map(fn ($x) => [
                        'title' => $x->key.' · '.strtoupper((string) $x->channel).' ('.$x->locale.')',
                        'sub' => mb_substr((string) ($x->subject ?: $x->name), 0, 44),
                        'icon' => 'doc',
                        'action' => [
                            'key' => 'tpl_edit_'.$x->id, 'label' => 'Edit — '.$x->key,
                            'method' => 'POST', 'endpoint' => '/papi/comms/templates/save', 'actor' => 'human',
                            'body' => ['id' => $x->id, 'key' => (string) $x->key, 'locale' => (string) $x->locale],
                            'schema' => ['name' => 'text', 'subject' => 'text', 'text_body' => 'textarea', 'html_body' => 'textarea', 'is_active' => 'enum:1,0'],
                            'labels' => ['name' => 'Name', 'subject' => 'Subject', 'text_body' => 'Text body', 'html_body' => 'HTML body', 'is_active' => 'Active? (1 = yes)'],
                            'prefill' => ['name' => (string) $x->name, 'subject' => (string) ($x->subject ?? ''),
                                'text_body' => (string) ($x->text_body ?? ''), 'html_body' => (string) ($x->html_body ?? ''),
                                'is_active' => $x->is_active ? '1' : '0'],
                        ],
                    ])->values()->all()];
            }
            $quick[] = ['key' => 'seed_tpl', 'label' => 'Install default templates', 'method' => 'POST', 'endpoint' => '/papi/comms/templates/seed', 'actor' => 'human',
                'confirm' => 'Install/refresh the default template pack? Existing keys are kept.'];

            $quick[] = ['key' => 'add_provider', 'label' => 'Add provider', 'method' => 'POST', 'endpoint' => '/papi/comms/providers/save', 'actor' => 'human',
                'schema' => ['name' => 'text', 'driver' => 'enum:smtp,mailgun,sendgrid,postmark,brevo', 'priority' => 'number', 'host' => 'text', 'port' => 'number', 'username' => 'text', 'password' => 'text', 'api_key' => 'text', 'domain' => 'text'],
                'labels' => ['name' => 'Name', 'driver' => 'Driver', 'priority' => 'Priority (1 = first)', 'host' => 'SMTP host (smtp only)', 'port' => 'SMTP port (smtp only)', 'username' => 'SMTP username', 'password' => 'SMTP password', 'api_key' => 'API key (api drivers)', 'domain' => 'Sending domain (mailgun)'],
                'prefill' => ['driver' => 'smtp', 'priority' => 1, 'port' => 587]];
            foreach ($provs->take(5) as $p) {
                $quick[] = ['key' => 'test_prov_'.$p->id, 'label' => 'Test — '.$p->name, 'method' => 'POST',
                    'endpoint' => '/papi/comms/providers/'.$p->id.'/test', 'actor' => 'human',
                    'confirm' => 'Send a test email through "'.$p->name.'" to the owner address?'];
            }

            $segEnum = 'enum:all,tag,city,customer_type,ordered_within_days,not_ordered_within_days,ordered_between,not_ordered_between,bought_product,bought_category,not_bought_category';
            $segHelp = 'Audience value: tag / city / customer type (wholesale…) / number of days / product id / category name / a "from..to" date range';
            $quick[] = ['key' => 'new_campaign', 'label' => 'New campaign (draft)', 'method' => 'POST', 'endpoint' => '/papi/comms/campaigns/save-simple', 'actor' => 'human',
                'schema' => ['name' => 'text', 'channel' => 'enum:email,whatsapp,both', 'segment_type' => $segEnum, 'segment_value' => 'text',
                    'offer_product_id' => 'number', 'offer_category' => 'text', 'offer_promo' => 'text',
                    'subject' => 'text', 'text_body' => 'textarea', 'throttle_per_minute' => 'number'],
                'labels' => ['name' => 'Campaign name', 'channel' => 'Channel', 'segment_type' => 'Audience', 'segment_value' => $segHelp,
                    'offer_product_id' => 'Promote product id (optional — fills {{product_name}} / {{price}})',
                    'offer_category' => 'Promote category (optional — fills {{category}})',
                    'offer_promo' => 'Promo line (optional — fills {{promo}})',
                    'subject' => 'Subject (email)', 'text_body' => 'Message body ({{customer_name}}, {{product_name}}, {{price}} …)', 'throttle_per_minute' => 'Send rate per minute'],
                'prefill' => ['channel' => 'whatsapp', 'segment_type' => 'all', 'throttle_per_minute' => 60]];
            $quick[] = ['key' => 'estimate_audience', 'label' => 'Estimate an audience', 'method' => 'POST', 'endpoint' => '/papi/comms/campaigns/estimate-simple', 'actor' => 'human',
                'schema' => ['channel' => 'enum:email,whatsapp,both', 'segment_type' => $segEnum, 'segment_value' => 'text'],
                'labels' => ['channel' => 'Channel', 'segment_type' => 'Audience', 'segment_value' => $segHelp],
                'prefill' => ['channel' => 'whatsapp', 'segment_type' => 'all']];
            $quick = array_merge($quick, $campActions);

            $quick[] = ['key' => 'suppress_add', 'label' => 'Suppress an address', 'method' => 'POST', 'endpoint' => '/papi/comms/suppressions/add', 'actor' => 'human',
                'schema' => ['channel' => 'enum:email,whatsapp', 'address' => 'text', 'reason' => 'text'],
                'labels' => ['channel' => 'Channel', 'address' => 'Email address / phone', 'reason' => 'Reason'],
                'prefill' => ['channel' => 'email', 'reason' => 'manual']];
            if ($supps->count()) {
                $quick[] = ['key' => 'suppress_rm', 'label' => 'Un-suppress an address', 'method' => 'POST', 'endpoint' => '/papi/comms/suppressions/remove', 'actor' => 'human',
                    'schema' => ['address' => 'combo:'.$supps->pluck('address')->unique()->take(30)->implode('|')],
                    'labels' => ['address' => 'Address to release']];
            }
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:communication', 'title' => 'Email Center',
                'subtitle' => ($live ? 'LIVE' : 'Dark launch').' · '.$sent24.' sent in 24h · '.$failed.' failed',
                'status' => $live ? 'active' : 'inactive',
            ])
            ->headline($live ? 'LIVE' : 'DARK', 'Email Center', $live ? 'ok' : 'warn')
            ->financials([
                ['key' => 'sent24', 'label' => 'Sent (24h)', 'value' => (string) $sent24, 'tone' => 'ok'],
                ['key' => 'failed', 'label' => 'Failed', 'value' => (string) $failed, 'tone' => $failed > 0 ? 'danger' : 'ok', 'foot' => 'retry from Quick actions'],
                ['key' => 'queued', 'label' => 'Queued', 'value' => (string) $queued, 'tone' => $queued > 0 ? 'info' : 'default'],
                ['key' => 'supp', 'label' => 'Suppressed', 'value' => (string) $suppTot, 'foot' => 'never contacted'],
                ['key' => 'wa_today', 'label' => 'WhatsApp marketing today',
                    'value' => number_format($waSent).' / '.number_format($waLimit),
                    'tone' => $waLeft > 0 ? 'ok' : 'warn',
                    'foot' => number_format($waLeft).' left today · internal cap, not a WhatsApp-guaranteed limit'],
                ['key' => 'optin', 'label' => 'Marketing opt-in',
                    'value' => number_format($optInCount),
                    'tone' => $optInCount > 0 ? 'ok' : 'default',
                    'foot' => $optOutCount > 0 ? (number_format($optOutCount).' opted out') : 'explicit consent only'],
            ])
            ->quickActions($quick)
            ->tabSections([
                'overview' => [$statusCard, $numbersCard],
                'queue' => [$queueCard],
                'templates' => [$tplCard],
                'providers' => [$provCard],
                'routing' => [$routeCard],
                'campaigns' => [$campCard],
                'suppressions' => [$suppCard],
                'setup' => [$setupCard],
            ])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'queue', 'label' => 'Queue', 'count' => $queued + $failed],
                ['key' => 'templates', 'label' => 'Templates', 'count' => count($tplRows)],
                ['key' => 'providers', 'label' => 'Providers', 'count' => count($provRows)],
                ['key' => 'routing', 'label' => 'Routing'],
                ['key' => 'campaigns', 'label' => 'Campaigns', 'count' => count($campRows)],
                ['key' => 'suppressions', 'label' => 'Suppressions', 'count' => $suppTot],
                ['key' => 'setup', 'label' => 'Setup'],
            ])
            ->meta(['capability' => 'communication', 'ttl' => 20, 'layout' => 'record', 'permissions' => ['menu:settings']]);
    }

    /** Short human label for a campaign's audience definition (for the campaigns list). */
    private static function audienceLabel(array $seg): string
    {
        if (! empty($seg['bought_category']))     return 'Bought: '.$seg['bought_category'];
        if (! empty($seg['not_bought_category'])) return 'Not bought: '.$seg['not_bought_category'];
        if (! empty($seg['bought_product']))      return 'Bought product #'.$seg['bought_product'];
        if (! empty($seg['customer_type']))       return ucfirst((string) $seg['customer_type']);
        if (! empty($seg['segment']))             return 'Tag: '.$seg['segment'];
        if (! empty($seg['city']))                return 'City: '.$seg['city'];
        if (! empty($seg['ordered_within_days'])) return 'Ordered ≤ '.$seg['ordered_within_days'].'d';
        if (! empty($seg['not_ordered_within_days'])) return 'Winback > '.$seg['not_ordered_within_days'].'d';
        if (! empty($seg['ordered_between']))     return 'Ordered in range';
        if (! empty($seg['not_ordered_between'])) return 'Not ordered in range';

        return 'All opted-in';
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        if (! $this->backendReady()) {
            return ['live' => false, 'installed' => false];
        }
        $tid = $ctx->tenantId();
        $msgs = fn () => CommMessage::withoutGlobalScope('tenant')->where('tenant_id', $tid);

        return [
            'live'   => (bool) $ctx->tenant->setting('comms_email_live', false),
            'failed' => (clone $msgs())->where('status', 'failed')->count(),
            'queued' => (clone $msgs())->whereIn('status', ['pending', 'retry', 'sending'])->count(),
            'sent24' => (clone $msgs())->where('status', 'sent')->where('sent_at', '>=', now()->subDay())->count(),
        ];
    }

    /** True once `php artisan migrate` has created the Comms tables on this server. */
    private function backendReady(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable('comm_messages');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
