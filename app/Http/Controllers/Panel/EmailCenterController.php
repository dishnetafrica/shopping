<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\CommEvent;
use App\Models\CommMessage;
use App\Models\CommProvider;
use App\Models\CommSuppression;
use App\Models\CommTemplate;
use App\Services\Comms\EmailDispatcher;
use App\Services\Comms\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Email Center — Communication → Email (Milestone 1).
 *
 * One static page (resources/panel/email-center.html, tabs: Dashboard / Queue &
 * Logs / Templates / Providers / Settings) + these /papi JSON endpoints, same
 * shape as every other panel screen (FxController / SupplierController style).
 *
 * Read = any panel user with the menu key. Write (providers, templates,
 * settings, retry/cancel) = owner-like only.
 */
class EmailCenterController extends Controller
{
    public function page(Request $request)
    {
        $path = resource_path('panel/email-center.html');
        if (! is_file($path)) return response('email-center.html missing', 404);
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    // ---------------------------------------------------------------- helpers

    protected function tenant(Request $request)
    {
        $u = $request->user();
        return $u?->tenant;
    }

    protected function ownerGate(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant) return response()->json(['ok' => false], 401);
        if (! $u->isOwnerLike())  return response()->json(['ok' => false, 'error' => 'owner_only'], 403);
        return null;
    }

    // -------------------------------------------------------------- dashboard

    public function stats(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $base = DB::table('comm_messages')->where('tenant_id', $t->id)->where('channel', 'email');

        $today = now()->startOfDay();
        $week  = now()->subDays(7);

        $agg = function ($since) use ($base) {
            $q = (clone $base)->where('created_at', '>=', $since);
            return [
                'sent'    => (clone $q)->where('status', 'sent')->count(),
                'failed'  => (clone $q)->where('status', 'failed')->count(),
                'queued'  => (clone $q)->whereIn('status', ['pending', 'retry', 'sending'])->count(),
                'opened'  => (clone $q)->whereNotNull('opened_at')->count(),
                'bounced' => (clone $q)->whereNotNull('bounced_at')->count(),
            ];
        };

        $recentFail = (clone $base)->where('status', 'failed')->orderByDesc('id')->limit(5)
            ->get(['id', 'to_email', 'subject', 'last_error', 'created_at']);

        $providers = CommProvider::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('channel', 'email')
            ->orderBy('priority')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'driver' => $p->driver,
                'is_active' => $p->is_active, 'priority' => $p->priority,
                'sent_today' => $p->sent_today, 'daily_limit' => $p->daily_limit,
                'last_error' => $p->last_error ? Str::limit($p->last_error, 160) : null,
                'last_used_at' => optional($p->last_used_at)->toDateTimeString(),
            ]);

        return response()->json([
            'ok'          => true,
            'today'       => $agg($today),
            'week'        => $agg($week),
            'recent_fail' => $recentFail,
            'providers'   => $providers,
            'from_ok'     => (bool) $t->setting('comms_email.from_email'),
        ]);
    }

    // ----------------------------------------------------------- queue & logs

    public function messages(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $per  = 25;
        $page = max(1, (int) $request->query('page', 1));
        $q    = DB::table('comm_messages')->where('tenant_id', $t->id)->where('channel', 'email');

        if ($st = $request->query('status')) {
            if ($st === 'queued') $q->whereIn('status', ['pending', 'retry', 'sending']);
            else                  $q->where('status', $st);
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(function ($w) use ($s) {
                $w->where('to_email', 'ilike', "%{$s}%")
                  ->orWhere('subject', 'ilike', "%{$s}%")
                  ->orWhere('template_key', 'ilike', "%{$s}%");
            });
        }

        $total = (clone $q)->count();
        $rows  = $q->orderByDesc('id')->offset(($page - 1) * $per)->limit($per)->get([
            'id', 'to_email', 'to_name', 'subject', 'template_key', 'status', 'attempts',
            'provider_id', 'last_error', 'scheduled_at', 'next_attempt_at', 'sent_at',
            'delivered_at', 'opened_at', 'opens', 'clicks', 'created_at',
        ]);

        return response()->json([
            'ok' => true, 'items' => $rows, 'total' => $total,
            'page' => $page, 'pages' => (int) ceil(max(1, $total) / $per),
        ]);
    }

    public function messageShow(Request $request, int $id)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $m = CommMessage::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $m) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $events = CommEvent::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('message_id', $m->id)
            ->orderBy('id')->get(['event', 'provider', 'occurred_at']);

        $att = collect((array) $m->attachments)
            ->map(fn ($a) => ['name' => $a['name'] ?? 'attachment', 'mime' => $a['mime'] ?? null])
            ->values();

        return response()->json(['ok' => true, 'message' => [
            'id' => $m->id, 'to_email' => $m->to_email, 'to_name' => $m->to_name,
            'cc' => $m->cc, 'bcc' => $m->bcc, 'subject' => $m->subject,
            'template_key' => $m->template_key, 'status' => $m->status,
            'attempts' => $m->attempts, 'max_attempts' => $m->max_attempts,
            'provider' => optional($m->provider)->name,
            'provider_message_id' => $m->provider_message_id,
            'last_error' => $m->last_error,
            'html_body' => $m->html_body, 'text_body' => $m->text_body,
            'attachments' => $att,
            'opens' => $m->opens, 'clicks' => $m->clicks,
            'sent_at' => optional($m->sent_at)->toDateTimeString(),
            'delivered_at' => optional($m->delivered_at)->toDateTimeString(),
            'opened_at' => optional($m->opened_at)->toDateTimeString(),
            'bounced_at' => optional($m->bounced_at)->toDateTimeString(),
            'created_at' => optional($m->created_at)->toDateTimeString(),
        ], 'events' => $events]);
    }

    public function messageRetry(Request $request, int $id)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $m = CommMessage::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $m) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if (! in_array($m->status, [CommMessage::ST_FAILED, CommMessage::ST_CANCELLED], true)) {
            return response()->json(['ok' => false, 'error' => 'only failed/cancelled can be retried'], 422);
        }

        // Re-queue as RETRY with an immediate next_attempt_at; the comms sweep (CommsEmailSweep) re-sends
        // it through the CORRECT channel dispatcher. Sending inline here made a manual retry depend on a
        // live provider (a failed send flipped straight back to 'failed') and always dispatched the email
        // job even for a WhatsApp row.
        $m->status = CommMessage::ST_RETRY;
        $m->attempts = 0;
        $m->next_attempt_at = now();
        $m->last_error = null;
        $m->save();

        return response()->json(['ok' => true]);
    }

    public function messageCancel(Request $request, int $id)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $m = CommMessage::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $m) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        if (! in_array($m->status, [CommMessage::ST_PENDING, CommMessage::ST_RETRY], true)) {
            return response()->json(['ok' => false, 'error' => 'only queued messages can be cancelled'], 422);
        }

        $m->status = CommMessage::ST_CANCELLED;
        $m->save();

        return response()->json(['ok' => true]);
    }

    // -------------------------------------------------------------- templates

    public function templates(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $rows = CommTemplate::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('channel', 'email')
            ->orderBy('key')->orderBy('locale')->get();

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    public function templateSave(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $data = $request->validate([
            'id'        => 'nullable|integer',
            'key'       => 'required|string|max:60',
            'locale'    => 'required|string|max:10',
            'name'      => 'required|string|max:190',
            'subject'   => 'nullable|string|max:250',
            'html_body' => 'nullable|string',
            'text_body' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if (! empty($data['id'])) {
            $tpl = CommTemplate::withoutGlobalScope('tenant')
                ->where('tenant_id', $t->id)->where('id', $data['id'])->first();
            if (! $tpl) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        } else {
            $dupe = CommTemplate::withoutGlobalScope('tenant')
                ->where('tenant_id', $t->id)->where('channel', 'email')
                ->where('key', $data['key'])->where('locale', $data['locale'])->exists();
            if ($dupe) return response()->json(['ok' => false, 'error' => 'key+locale already exists'], 422);
            $tpl = new CommTemplate(['tenant_id' => $t->id, 'channel' => 'email']);
        }

        $tpl->fill([
            'key' => Str::slug($data['key'], '_'), 'locale' => $data['locale'],
            'name' => $data['name'], 'subject' => $data['subject'] ?? null,
            'html_body' => $data['html_body'] ?? null, 'text_body' => $data['text_body'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $tpl->tenant_id = $t->id;
        $tpl->save();

        return response()->json(['ok' => true, 'id' => $tpl->id]);
    }

    // -------------------------------------------------------------- providers

    public function providers(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $rows = CommProvider::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('channel', 'email')
            ->orderBy('priority')->orderBy('id')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'driver' => $p->driver,
                'priority' => $p->priority, 'is_active' => $p->is_active,
                'daily_limit' => $p->daily_limit, 'sent_today' => $p->sent_today,
                'last_error' => $p->last_error,
                'last_used_at' => optional($p->last_used_at)->toDateTimeString(),
                'config' => $p->safeConfig(),
            ]);

        return response()->json(['ok' => true, 'items' => $rows, 'drivers' => CommProvider::DRIVERS]);
    }

    public function providerSave(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $data = $request->validate([
            'id'          => 'nullable|integer',
            'name'        => 'required|string|max:120',
            'driver'      => 'required|string|in:smtp,mailgun,sendgrid,postmark,brevo',
            'priority'    => 'required|integer|min:1|max:999',
            'is_active'   => 'boolean',
            'daily_limit' => 'nullable|integer|min:0',
            'config'      => 'required|array',
        ]);

        if (! empty($data['id'])) {
            $p = CommProvider::withoutGlobalScope('tenant')
                ->where('tenant_id', $t->id)->where('id', $data['id'])->first();
            if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        } else {
            $p = new CommProvider();
            $p->tenant_id = $t->id;
            $p->channel   = 'email';
        }

        // Masked secrets ('••••••') mean "keep what is stored".
        $old = $p->exists ? ($p->config ?? []) : [];
        $cfg = $data['config'];
        foreach (['password', 'api_key', 'server_token'] as $k) {
            if (isset($cfg[$k]) && str_contains((string) $cfg[$k], '••')) {
                $cfg[$k] = $old[$k] ?? null;
            }
        }

        $p->name        = $data['name'];
        $p->driver      = $data['driver'];
        $p->priority    = (int) $data['priority'];
        $p->is_active   = (bool) ($data['is_active'] ?? true);
        $p->daily_limit = $data['daily_limit'] !== null ? (int) $data['daily_limit'] : null;
        $p->config      = $cfg;
        $p->save();

        return response()->json(['ok' => true, 'id' => $p->id]);
    }

    public function providerDelete(Request $request, int $id)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        CommProvider::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Synchronous single-provider test send (bypasses queue/failover ON PURPOSE
     * so the operator sees THIS provider's real error immediately).
     */
    public function providerTest(Request $request, int $id, EmailDispatcher $dispatcher, EmailService $email)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $to = trim((string) $request->input('to'));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'valid "to" address required'], 422);
        }

        $p = CommProvider::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('id', $id)->first();
        if (! $p) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        $from = $dispatcher->fromIdentity($t);
        if (empty($from['email'])) {
            return response()->json(['ok' => false, 'error' => 'set the From address in Settings first'], 422);
        }

        // Build a real logged message so the test also lands in the audit trail.
        $m = $email->queueRaw($t, $to, 'CloudBSS Email Center — test message',
            '<p>This is a <b>test email</b> from the CloudBSS Email Center.</p><p>Provider: '.e($p->name).' ('.e($p->driver).')</p>',
            null, ['meta' => ['test' => true, 'provider_id' => $p->id]]);

        // The queueRaw dispatch may already be racing on sync queues — claim it back for a direct attempt.
        $m->refresh();
        if ($m->status === \App\Models\CommMessage::ST_SENT) {
            return response()->json(['ok' => true, 'note' => 'sent via failover pipeline', 'message_id' => $m->provider_message_id]);
        }
        $m->status = \App\Models\CommMessage::ST_SENDING;
        $m->save();

        $impl = $dispatcher->make($p->driver);
        if (! $impl) return response()->json(['ok' => false, 'error' => 'unknown driver'], 422);

        $result = $impl->send($p, $m, $from);
        if ($result->ok) {
            $p->recordSend();
            $m->status = \App\Models\CommMessage::ST_SENT;
            $m->provider_id = $p->id;
            $m->provider_message_id = $result->messageId;
            $m->sent_at = now();
            $m->attempts = $m->attempts + 1;
            $m->save();
            return response()->json(['ok' => true, 'message_id' => $result->messageId]);
        }

        $m->status = \App\Models\CommMessage::ST_FAILED;
        $m->attempts = $m->attempts + 1;
        $m->last_error = $result->error;
        $m->save();
        $p->last_error = $result->error;
        $p->save();

        return response()->json(['ok' => false, 'error' => $result->error]);
    }

    // --------------------------------------------------------------- settings

    public function settingsGet(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        // Lazily mint the webhook secret so the URLs below are always usable.
        $secret = (string) $t->setting('comms_email.webhook_secret', '');
        if ($secret === '') {
            $secret = Str::lower(Str::random(32));
            $t->putSetting('comms_email.webhook_secret', $secret);
        }

        $base = rtrim((string) config('app.url'), '/');
        $hooks = [];
        foreach (['mailgun', 'sendgrid', 'postmark', 'brevo'] as $d) {
            $hooks[$d] = $base.'/papi/comms/hook/'.$d.'?t='.$t->id.'&s='.$secret;
        }

        return response()->json(['ok' => true, 'settings' => [
            'from_name'   => (string) $t->setting('comms_email.from_name', ''),
            'from_email'  => (string) $t->setting('comms_email.from_email', ''),
            'reply_to'    => (string) $t->setting('comms_email.reply_to', ''),
            'footer_text' => (string) $t->setting('comms_email.footer_text', ''),
            'live'        => (bool) $t->setting('comms_email_live', false),
        ], 'webhooks' => $hooks]);
    }

    public function settingsSave(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $data = $request->validate([
            'from_name'   => 'nullable|string|max:120',
            'from_email'  => 'nullable|email|max:190',
            'reply_to'    => 'nullable|email|max:190',
            'footer_text' => 'nullable|string|max:2000',
            'live'        => 'nullable|boolean',
        ]);

        foreach (['from_name', 'from_email', 'reply_to', 'footer_text'] as $k) {
            $t->putSetting('comms_email.'.$k, (string) ($data[$k] ?? ''));
        }

        // Go-live gate (M2): modules only route through the Email Center when this is on.
        // Refuse to enable half-configured — a live tenant with no From/provider would
        // silently queue-and-fail customer emails.
        if (array_key_exists('live', $data)) {
            $wantLive = (bool) $data['live'];
            if ($wantLive) {
                $hasFrom = (string) $t->setting('comms_email.from_email', '') !== '';
                $hasProv = CommProvider::withoutGlobalScope('tenant')
                    ->where('tenant_id', $t->id)->where('channel', 'email')
                    ->where('is_active', true)->exists();
                if (! $hasFrom || ! $hasProv) {
                    return response()->json(['ok' => false, 'error' => 'To go live, set a From email and add at least one active provider first.'], 422);
                }
            }
            $t->putSetting('comms_email_live', $wantLive);
        }

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------- v3 capability adapters (G2)
    // The v3 shell submits flat form fields to fixed endpoints; these thin adapters
    // translate and DELEGATE to the methods above — no logic is duplicated.

    /**
     * Body-id alias for messageRetry. The v3 combo picker sends the option label
     * verbatim ("123 — user@x · bounced"); PHP's (int) cast takes the leading digits,
     * so the id arrives intact. Same for cancel below.
     */
    public function messageRetryBody(Request $request)
    {
        return $this->messageRetry($request, (int) $request->input('id'));
    }

    public function messageCancelBody(Request $request)
    {
        return $this->messageCancel($request, (int) $request->input('id'));
    }

    /**
     * Flat routing form (ev_<event> = off|email|whatsapp|both) → routes[] map,
     * then straight into routesSave (which owns validation + persistence).
     */
    public function routesSaveSimple(Request $request)
    {
        $map = [];
        foreach (array_keys(\App\Services\Comms\CommCenter::DEFAULT_ROUTES) as $event) {
            $v = strtolower(trim((string) $request->input('ev_'.$event, '')));
            if ($v === '') continue;                         // untouched → keep current
            $map[$event] = match ($v) {
                'off' => [], 'email' => ['email'], 'whatsapp' => ['whatsapp'],
                'both' => ['email', 'whatsapp'], default => null,
            };
            if ($map[$event] === null) unset($map[$event]);
        }
        $request->merge(['routes' => $map]);

        return $this->routesSave($request);
    }

    /**
     * v3 flat-form segment: segment_type + segment_value → the assoc map
     * CampaignService::buildSegmentQuery understands. 'all' (or blank) = everyone.
     */
    private function segmentFromSimple(Request $request): array
    {
        $type = strtolower(trim((string) $request->input('segment_type', 'all')));
        $val  = trim((string) $request->input('segment_value', ''));
        return match ($type) {
            'tag'                     => $val !== '' ? ['segment' => $val] : [],
            'city'                    => $val !== '' ? ['city' => $val] : [],
            'customer_type'           => $val !== '' ? ['customer_type' => $val] : [],
            'ordered_within_days'     => (int) $val > 0 ? ['ordered_within_days' => (int) $val] : [],
            'not_ordered_within_days' => (int) $val > 0 ? ['not_ordered_within_days' => (int) $val] : [],
            'ordered_between'         => self::rangeSeg($val, 'ordered_between'),
            'not_ordered_between'     => self::rangeSeg($val, 'not_ordered_between'),
            'bought_product'          => (int) $val > 0 ? ['bought_product' => (int) $val] : [],
            'bought_category'         => $val !== '' ? ['bought_category' => $val] : [],
            'not_bought_category'     => $val !== '' ? ['not_bought_category' => $val] : [],
            default                   => [],
        };
    }

    /** Parse "2026-06-01..2026-06-30" (or " to ") into a date-range segment. */
    private static function rangeSeg(string $val, string $key): array
    {
        $parts = preg_split('/\s*(?:\.\.|\bto\b)\s*/i', $val, 2) ?: [];
        $from  = trim($parts[0] ?? '');
        $to    = trim($parts[1] ?? '');
        if ($from === '' && $to === '') {
            return [];
        }

        return [$key => ['from' => $from, 'to' => $to]];
    }

    /** Flat offer fields → the campaign's `offer` (product being promoted, for template vars). */
    private function offerFromSimple(Request $request): array
    {
        $offer = [];
        if ((int) $request->input('offer_product_id', 0) > 0) {
            $offer['product_id'] = (int) $request->input('offer_product_id');
        }
        if (trim((string) $request->input('offer_category', '')) !== '') {
            $offer['category'] = trim((string) $request->input('offer_category'));
        }
        if (trim((string) $request->input('offer_promo', '')) !== '') {
            $offer['promo'] = trim((string) $request->input('offer_promo'));
        }

        return $offer;
    }

    /** v3 New-campaign form (flat fields incl. audience + offer) → campaignSave. */
    public function campaignSaveSimple(Request $request)
    {
        $merge = [
            'segment' => $this->segmentFromSimple($request),
            'offer'   => $this->offerFromSimple($request),
        ];
        // The v3 flat form carries a single content field (text_body). An email campaign needs an
        // html_body, so when the channel includes email and none was supplied, use the text as the body
        // (the whatsapp side keeps text_body). Without this the simple form 422s on every email campaign.
        $channel = strtolower(trim((string) $request->input('channel', 'email')));
        if (in_array($channel, ['email', 'both'], true)
            && trim((string) $request->input('html_body', '')) === ''
            && trim((string) $request->input('text_body', '')) !== '') {
            $merge['html_body'] = (string) $request->input('text_body');
        }
        $request->merge($merge);

        return $this->campaignSave($request);
    }

    /** v3 audience preview: same flat fields → a human message for the toast. */
    public function campaignEstimateSimple(Request $request)
    {
        $request->merge(['segment' => $this->segmentFromSimple($request)]);
        $resp = $this->campaignEstimate($request, app(\App\Services\Comms\CampaignService::class));
        $j = $resp->getData(true);
        if (empty($j['ok'])) return $resp;

        return response()->json(['ok' => true, 'estimate' => $j['estimate'],
            'message' => 'Audience ≈ '.number_format((int) $j['estimate']).' customer'.((int) $j['estimate'] === 1 ? '' : 's')]);
    }

    // ------------------------------------------------------------ suppressions
    // Closes the M1 gap: the suppression list finally has management endpoints.
    // Auto-writes (bounce/complaint/unsubscribe) continue to come from the
    // dispatcher/webhooks; these are the owner's manual add/remove.

    public function suppressionAdd(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $data = $request->validate([
            'channel' => 'required|string|in:email,whatsapp',
            'address' => 'required|string|max:190',
            'reason'  => 'nullable|string|max:190',
        ]);

        $address = strtolower(trim($data['address']));
        CommSuppression::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $t->id, 'channel' => $data['channel'], 'address' => $address],
            ['reason' => (string) ($data['reason'] ?? 'manual'), 'source' => 'manual']
        );

        return response()->json(['ok' => true, 'message' => 'Suppressed '.$address]);
    }

    /** Remove by address (all channels) — a suppressed address is a natural key for the owner. */
    public function suppressionRemove(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $address = strtolower(trim((string) $request->input('address', '')));
        if ($address === '') {
            return response()->json(['ok' => false, 'error' => 'address required'], 422);
        }
        $n = CommSuppression::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('address', $address)->delete();

        return response()->json(['ok' => true, 'message' => 'Removed '.$n.' suppression'.($n === 1 ? '' : 's')]);
    }

    /** Owner installs / refreshes the default template pack from the panel. */
    public function seedTemplates(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        \Illuminate\Support\Facades\Artisan::call('comms:seed-templates', ['tenant' => $t->id]);

        return response()->json(['ok' => true, 'output' => trim(\Illuminate\Support\Facades\Artisan::output())]);
    }

    // ---------------------------------------------------------- routing (M3)

    /** The routing matrix: which channels carry each event for this tenant. */
    public function routesGet(Request $request)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $overrides = (array) $t->setting('comms_routes', []);
        $events    = [];
        foreach (\App\Services\Comms\CommCenter::DEFAULT_ROUTES as $event => $default) {
            $events[] = [
                'event'    => $event,
                'channels' => array_values($overrides[$event] ?? $default),
                'default'  => array_values($default),
            ];
        }

        return response()->json([
            'ok'          => true,
            'events'      => $events,
            'channels'    => ['email', 'whatsapp'],
            'whatsapp_ok' => (bool) $t->sendInstance(),
        ]);
    }

    public function routesSave(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $incoming = (array) $request->input('routes', []);
        $known    = array_keys(\App\Services\Comms\CommCenter::DEFAULT_ROUTES);
        $allowed  = ['email', 'whatsapp'];

        $clean = [];
        foreach ($incoming as $event => $channels) {
            if (! in_array($event, $known, true)) continue;           // unknown events dropped
            $chs = array_values(array_intersect($allowed, array_map('strval', (array) $channels)));
            $clean[$event] = $chs;                                    // [] = event fully muted
        }

        $t->putSetting('comms_routes', $clean);

        return response()->json(['ok' => true, 'routes' => $clean]);
    }

    // --------------------------------------------------------- campaigns (M4)

    public function campaigns(Request $request, \App\Services\Comms\CampaignService $svc)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $rows = \App\Models\CommCampaign::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->orderByDesc('id')->limit(100)->get()
            ->map(function ($c) use ($svc) {
                $s = $svc->stats($c);
                return [
                    'id' => $c->id, 'name' => $c->name, 'channel' => $c->channel,
                    'template_key' => $c->template_key, 'subject' => $c->subject,
                    'html_body' => $c->html_body, 'text_body' => $c->text_body,
                    'segment' => $c->segment ?: (object) [], 'status' => $c->status,
                    'scheduled_at' => optional($c->scheduled_at)->toDateTimeString(),
                    'finished_at' => optional($c->finished_at)->toDateTimeString(),
                    'total_estimated' => $c->total_estimated, 'enqueued_count' => $c->enqueued_count,
                    'throttle_per_minute' => $c->throttle_per_minute,
                    'stats' => $s,
                ];
            });

        return response()->json(['ok' => true, 'items' => $rows]);
    }

    public function campaignSave(Request $request)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $t = $this->tenant($request);

        $data = $request->validate([
            'id'                  => 'nullable|integer',
            'name'                => 'required|string|max:150',
            'channel'             => 'required|string|in:email,whatsapp,both',
            'template_key'        => 'nullable|string|max:60',
            'subject'             => 'nullable|string|max:250',
            'html_body'           => 'nullable|string',
            'text_body'           => 'nullable|string',
            'segment'             => 'nullable|array',
            'offer'               => 'nullable|array',
            'throttle_per_minute' => 'nullable|integer|min:1|max:600',
        ]);

        if (! empty($data['id'])) {
            $c = \App\Models\CommCampaign::withoutGlobalScope('tenant')
                ->where('tenant_id', $t->id)->where('id', $data['id'])->first();
            if (! $c) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            if (! $c->isEditable()) return response()->json(['ok' => false, 'error' => 'only draft/scheduled campaigns can be edited'], 422);
        } else {
            $c = new \App\Models\CommCampaign();
            $c->tenant_id = $t->id;
            $c->status    = \App\Models\CommCampaign::ST_DRAFT;
            $c->created_by = $request->user()->id;
        }

        // Content sanity: template XOR inline, per channel.
        $tplKey = $data['template_key'] ?? null;
        if (! $tplKey) {
            $needsEmail = in_array($data['channel'], ['email', 'both'], true);
            $needsWa    = in_array($data['channel'], ['whatsapp', 'both'], true);
            if ($needsEmail && empty($data['html_body'])) {
                return response()->json(['ok' => false, 'error' => 'email campaigns need a template or an HTML body'], 422);
            }
            if ($needsWa && empty($data['text_body'])) {
                return response()->json(['ok' => false, 'error' => 'whatsapp campaigns need a template or a text body'], 422);
            }
        }

        $seg = array_intersect_key((array) ($data['segment'] ?? []),
            array_flip(['segment', 'city', 'customer_type', 'ordered_within_days', 'not_ordered_within_days',
                'ordered_between', 'not_ordered_between', 'bought_product', 'bought_category', 'not_bought_category']));
        $offer = array_intersect_key((array) ($data['offer'] ?? []),
            array_flip(['product_id', 'product_ids', 'category', 'promo', 'link']));

        $c->fill([
            'name' => $data['name'], 'channel' => $data['channel'],
            'template_key' => $tplKey, 'subject' => $data['subject'] ?? null,
            'html_body' => $data['html_body'] ?? null, 'text_body' => $data['text_body'] ?? null,
            'segment' => $seg ?: null,
            'offer' => $offer ?: null,
            'throttle_per_minute' => (int) ($data['throttle_per_minute'] ?? 60),
        ]);
        $c->tenant_id = $t->id;
        $c->save();

        return response()->json(['ok' => true, 'id' => $c->id]);
    }

    public function campaignEstimate(Request $request, \App\Services\Comms\CampaignService $svc)
    {
        $t = $this->tenant($request);
        if (! $t) return response()->json(['ok' => false], 401);

        $seg = array_intersect_key((array) $request->input('segment', []),
            array_flip(['segment', 'city', 'customer_type', 'ordered_within_days', 'not_ordered_within_days',
                'ordered_between', 'not_ordered_between', 'bought_product', 'bought_category', 'not_bought_category']));
        $ch  = (string) $request->input('channel', 'email');
        if (! in_array($ch, \App\Models\CommCampaign::CHANNELS, true)) $ch = 'email';

        return response()->json(['ok' => true, 'estimate' => $svc->estimate($t, $seg, $ch)]);
    }

    /**
     * Dry-run preview of a saved campaign: the eligibility funnel + a rendered example, sending nothing.
     * Uses the same CampaignSafetyGuard as the real send (via CampaignPreview), so it can't mislead.
     */
    public function campaignPreview(Request $request, int $id)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $c = $this->campaignFor($request, $id);
        if ($c instanceof \Illuminate\Http\JsonResponse) return $c;

        $t = $this->tenant($request);
        $p = \App\Services\Comms\Marketing\CampaignPreview::for($t, $c);

        $msg = sprintf(
            "Audience: %s\nMatched from available order data: %s\nOpted-in: %s\nSuppressed: %s · Frequency-held: %s\n".
            "Daily capacity: %s of %s left today\nEligible now: %s%s\n\nExample:\n%s\n\n%s",
            $p['audience_def'],
            number_format($p['total_matching']),
            number_format($p['opted_in']),
            number_format($p['suppressed']), number_format($p['frequency_capped']),
            number_format($p['daily_remaining']), number_format($p['daily_limit']),
            number_format($p['eligible_now']),
            $p['sampled'] ? ' (sampled from first '.number_format($p['sample_cap']).')' : '',
            $p['example'],
            $p['data_caveat']
        );

        return response()->json(['ok' => true, 'message' => $msg, 'preview' => $p]);
    }

    protected function campaignFor(Request $request, int $id): \App\Models\CommCampaign|\Illuminate\Http\JsonResponse
    {
        $t = $this->tenant($request);
        $c = \App\Models\CommCampaign::withoutGlobalScope('tenant')
            ->where('tenant_id', $t->id)->where('id', $id)->first();
        return $c ?: response()->json(['ok' => false, 'error' => 'not_found'], 404);
    }

    public function campaignLaunch(Request $request, int $id, \App\Services\Comms\CampaignService $svc)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $c = $this->campaignFor($request, $id);
        if ($c instanceof \Illuminate\Http\JsonResponse) return $c;
        if (! in_array($c->status, [\App\Models\CommCampaign::ST_DRAFT, \App\Models\CommCampaign::ST_SCHEDULED], true)) {
            return response()->json(['ok' => false, 'error' => 'campaign already running or finished'], 422);
        }

        $when = $request->input('when') ? \Illuminate\Support\Carbon::parse($request->input('when')) : null;
        $svc->launch($c, $when);

        return response()->json(['ok' => true, 'status' => $c->status, 'scheduled_at' => optional($c->scheduled_at)->toDateTimeString(), 'total_estimated' => $c->total_estimated]);
    }

    public function campaignPause(Request $request, int $id, \App\Services\Comms\CampaignService $svc)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $c = $this->campaignFor($request, $id);
        if ($c instanceof \Illuminate\Http\JsonResponse) return $c;
        $svc->pause($c);
        return response()->json(['ok' => true, 'status' => $c->status]);
    }

    public function campaignResume(Request $request, int $id, \App\Services\Comms\CampaignService $svc)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $c = $this->campaignFor($request, $id);
        if ($c instanceof \Illuminate\Http\JsonResponse) return $c;
        $svc->resume($c);
        return response()->json(['ok' => true, 'status' => $c->status]);
    }

    public function campaignCancel(Request $request, int $id, \App\Services\Comms\CampaignService $svc)
    {
        if ($resp = $this->ownerGate($request)) return $resp;
        $c = $this->campaignFor($request, $id);
        if ($c instanceof \Illuminate\Http\JsonResponse) return $c;
        $svc->cancel($c);
        return response()->json(['ok' => true, 'status' => $c->status]);
    }
}
