<?php

namespace App\Services\Comms;

use App\Models\CommCampaign;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * M4 — the campaign engine. Owns THREE things and nothing else:
 *
 *   1. The audience query (customer_profiles + segment filters).
 *   2. The lifecycle (draft → scheduled → sending → done, pause/resume/cancel).
 *   3. The throttled batch enqueue (comms:campaign-sweep, once a minute):
 *      each pass takes the next `throttle_per_minute` profiles past the
 *      `last_enqueued_id` cursor and hands them to EmailService /
 *      WhatsAppService — which do suppression, rendering, failover, tracking
 *      exactly as for any other message. A campaign send is not special.
 *
 * Compliance: every campaign EMAIL is guaranteed an unsubscribe link — if the
 * body doesn't contain {{unsubscribe_url}}, a standard footer is appended
 * before enqueue. WhatsApp texts get a "reply STOP" line the same way; an
 * inbound STOP is the bot's concern (already suppressible via the panel).
 */
class CampaignService
{
    public function __construct(
        protected EmailService $email,
        protected WhatsAppService $whatsapp,
        protected TemplateRenderer $renderer,
    ) {
    }

    // ------------------------------------------------------------- audience

    /** Base audience query for one channel ('email'|'whatsapp'). */
    public function audienceQuery(Tenant $tenant, array $segment, string $channel): Builder
    {
        // v4.60 — a discovered business is not a campaign recipient. This audience is built with
        // DB::table(), so no Eloquent scope of any kind can reach it; the guard has to be the raw
        // fragment, and it has to be here, in the funnel every single-channel send passes through.
        $q = DB::table('customer_profiles')->where('tenant_id', $tenant->id)
            ->whereRaw(\App\Services\Crm\BusinessStage::sql());

        if ($channel === 'email') {
            $q->whereNotNull('email')->where('email', '!=', '');
        } else {
            $q->whereNotNull('phone')->whereRaw("length(phone) >= 7");
            // STRICT explicit marketing consent for WhatsApp. A prior inbound message is NOT consent,
            // so this is 0 until opt-in is captured — 0 opted-in → 0 broadcasts, by design.
            // Opt-out is deliberately NOT filtered here: an opted-out customer who once opted in stays
            // in the audience so the send path records an auditable 'skipped' (opted_out) row and the
            // funnel counts them — the hard no-send block lives in enqueueWhatsapp(). (Never-opted-in
            // stays excluded: they were never a recipient, so there is nothing to audit.)
            if (\Illuminate\Support\Facades\Schema::hasColumn('customer_profiles', 'marketing_opted_in_at')) {
                $q->whereNotNull('marketing_opted_in_at');
            }
        }

        // Phase 2 — all segment predicates (tag, city, customer type, recent/inactive, date ranges,
        // bought product/category) live in one place so this and cursorQuery() can never drift.
        Marketing\AudienceSegments::apply($q, $tenant, $segment);

        return $q;
    }

    /** @return array{email:int, whatsapp:int, total_profiles:int} */
    public function estimate(Tenant $tenant, array $segment, string $channel): array
    {
        $out = ['email' => 0, 'whatsapp' => 0, 'total_profiles' => 0];
        $chs = $channel === 'both' ? ['email', 'whatsapp'] : [$channel];
        foreach ($chs as $ch) {
            $out[$ch] = $this->audienceQuery($tenant, $segment, $ch)->count();
        }
        // "of N profiles" on the campaign screen means reachable customers, not rows in a table.
        $out['total_profiles'] = (int) DB::table('customer_profiles')->where('tenant_id', $tenant->id)
            ->whereRaw(\App\Services\Crm\BusinessStage::sql())->count();
        return $out;
    }

    /** How many messages THIS campaign has enqueued today (tenant-local day) — the per-campaign batch meter. */
    protected function campaignSentToday(CommCampaign $c, Tenant $tenant): int
    {
        $tz = (string) ($tenant->setting('timezone', config('app.timezone', 'UTC')) ?: 'UTC');
        try {
            $start = \Illuminate\Support\Carbon::now($tz)->startOfDay();
        } catch (\Throwable $e) {
            $start = \Illuminate\Support\Carbon::now()->startOfDay();
        }

        return (int) DB::table('comm_messages')
            ->where('tenant_id', $c->tenant_id)
            ->where('related_type', 'campaign')->where('related_id', $c->id)
            ->where('created_at', '>=', $start)
            ->count();
    }

    // ------------------------------------------------------------ lifecycle

    /** Launch (now or at $when). Locks in the estimate; the sweep does the rest. */
    public function launch(CommCampaign $c, ?\DateTimeInterface $when = null): void
    {
        $tenant = Tenant::find($c->tenant_id);
        $est    = $this->estimate($tenant, (array) $c->segment, $c->channel);

        $c->total_estimated = array_sum(array_intersect_key($est, array_flip($c->channels())));
        $c->status          = CommCampaign::ST_SCHEDULED;
        $c->scheduled_at    = $when ?: now();
        $c->save();
    }

    public function pause(CommCampaign $c): void
    {
        if (in_array($c->status, [CommCampaign::ST_SCHEDULED, CommCampaign::ST_SENDING], true)) {
            $c->status = CommCampaign::ST_PAUSED;
            $c->save();
        }
    }

    public function resume(CommCampaign $c): void
    {
        if ($c->status === CommCampaign::ST_PAUSED) {
            $c->status = $c->started_at ? CommCampaign::ST_SENDING : CommCampaign::ST_SCHEDULED;
            $c->save();
        }
    }

    public function cancel(CommCampaign $c): void
    {
        if (! in_array($c->status, [CommCampaign::ST_DONE, CommCampaign::ST_CANCELLED], true)) {
            $c->status      = CommCampaign::ST_CANCELLED;
            $c->finished_at = now();
            $c->save();
        }
    }

    // -------------------------------------------------------------- the sweep

    /** Called by comms:campaign-sweep every minute. Returns rows enqueued this pass. */
    public function processDue(): int
    {
        $due = CommCampaign::withoutGlobalScope('tenant')
            ->whereIn('status', [CommCampaign::ST_SCHEDULED, CommCampaign::ST_SENDING])
            ->where(function ($q) { $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()); })
            ->orderBy('id')->limit(10)->get();

        $total = 0;
        foreach ($due as $c) {
            $total += $this->enqueueBatch($c);
        }
        return $total;
    }

    /** One throttle-sized batch for one campaign. */
    public function enqueueBatch(CommCampaign $c): int
    {
        $tenant = Tenant::find($c->tenant_id);
        if (! $tenant) return 0;

        if ($c->status === CommCampaign::ST_SCHEDULED) {
            $c->status     = CommCampaign::ST_SENDING;
            $c->started_at = $c->started_at ?: now();
            $c->save();
        }

        [$subject, $html, $waText] = $this->content($tenant, $c);

        // Per-tenant daily cap paces the WhatsApp side: this pass is capped to the day's remaining
        // budget. When 0 remain, enqueue nothing and DON'T advance the cursor — the audience isn't
        // exhausted, the day's budget is; it resumes on a later sweep (after midnight, tenant-local).
        $fullBatch  = max(1, (int) $c->throttle_per_minute);
        $batchLimit = $fullBatch;
        if (in_array('whatsapp', $c->channels(), true)) {
            $remaining = Marketing\DailyLimiter::remaining($tenant);
            if ($remaining <= 0) {
                $c->save();                       // persist the SCHEDULED→SENDING transition above
                return 0;
            }
            $batchLimit = min($batchLimit, $remaining);
        }

        // Per-campaign daily batch — "send X/day, spread over the week." Caps THIS campaign to its own
        // daily_send_cap regardless of channel; when today's share is spent, enqueue nothing and DON'T
        // advance the cursor, so it resumes tomorrow until the audience is exhausted.
        $perDay = (int) ($c->daily_send_cap ?? 0);
        if ($perDay > 0) {
            $campaignLeft = $perDay - $this->campaignSentToday($c, $tenant);
            if ($campaignLeft <= 0) {
                $c->save();
                return 0;
            }
            $batchLimit = min($batchLimit, $campaignLeft);
        }

        // ONE cursor walk over profiles (union of both channel audiences when
        // 'both'), so a customer with email+phone advances the cursor once.
        $rows = $this->cursorQuery($tenant, $c)
            ->orderBy('customer_profiles.id')
            ->limit($batchLimit)
            ->get([
                'customer_profiles.id', 'customer_profiles.name', 'customer_profiles.email', 'customer_profiles.phone',
                'customer_profiles.marketing_opted_in_at', 'customer_profiles.marketing_opted_out_at',
            ]);

        // Offer variables ({{product_name}}, {{price}}, {{category}}, {{product_link}}) are campaign-
        // level — resolved once, merged into every recipient's vars.
        $offerVars = Marketing\OfferVars::build($tenant, $c->offer);

        $made = 0;
        foreach ($rows as $p) {
            $vars = ['customer_name' => (string) ($p->name ?: 'Customer')] + $offerVars;
            $opts = [
                'related_type' => 'campaign',
                'related_id'   => $c->id,
                'meta'         => ['campaign' => $c->name],
            ];

            foreach ($c->channels() as $ch) {
                if ($ch === 'email' && $p->email) {
                    if ($this->already($c, 'email', mb_strtolower(trim($p->email)))) continue;
                    if ($c->template_key) {
                        $this->email->queueTemplate($tenant, $c->template_key, $p->email, $vars, $opts + ['to_name' => $p->name]);
                    } else {
                        $body = $this->withUnsub((string) $html);
                        $subj = $this->renderer->renderString((string) $subject, array_merge($this->renderer->defaults($tenant), $vars), false);
                        $bodyR = $this->renderer->renderString($body, array_merge($this->renderer->defaults($tenant), $vars), true);
                        $this->email->queueRaw($tenant, $p->email, $subj, $bodyR, null, $opts + ['to_name' => $p->name]);
                    }
                    $made++;
                }
                if ($ch === 'whatsapp' && $p->phone) {
                    $made += $this->enqueueWhatsapp($tenant, $c, $p, (string) $waText, $vars, $opts);
                }
            }

            $c->last_enqueued_id = $p->id;
        }

        $c->enqueued_count = $c->enqueued_count + $made;

        // Done when the cursor has reached the end of the audience. Fewer rows than asked for proves
        // that directly; when the final batch fills EXACTLY to batchLimit, we confirm exhaustion by
        // checking there is nothing left past the advanced cursor — otherwise DONE would be deferred a
        // whole (empty) sweep. A daily cap only lowers batchLimit; it never yields fewer rows than the
        // audience holds, and cursorQuery() still sees the remainder, so a cap-shortened batch that
        // fills to batchLimit is correctly NOT "done".
        if (count($rows) < $batchLimit || ! $this->cursorQuery($tenant, $c)->exists()) {
            $c->status      = CommCampaign::ST_DONE;
            $c->finished_at = now();
        }
        $c->save();

        return $made;
    }

    /**
     * Enqueue one WhatsApp campaign message through the safety guard. Returns 1 if queued, else 0 —
     * and records an auditable `skipped` row with the reason (never a silent drop), so the recipient
     * isn't re-evaluated on the next pass.
     */
    protected function enqueueWhatsapp(Tenant $tenant, CommCampaign $c, object $p, string $waText, array $vars, array $opts): int
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $p->phone);
        if (strlen($digits) < 7) {
            return 0;
        }

        // Strict consent — belt to the SQL audience filter, and the ONLY consent gate for a 'both'
        // campaign whose phone row entered the union via email eligibility.
        // Never opted in → not a marketing recipient at all: silent, nothing to audit.
        if ($p->marketing_opted_in_at === null) {
            return 0;
        }
        // Opted out → an EXPLICIT hard no-send block that also leaves an auditable trail. This is the
        // authoritative opt-out enforcement point (belt-level, not reliant on the guard below), so
        // opt-out is honoured even if a suppression row is ever missing. Never sent; recorded as a skip.
        if ($p->marketing_opted_out_at !== null) {
            $this->recordSkip($tenant, $c, $digits, Marketing\CampaignSafetyGuard::R_OPTED_OUT, (string) ($p->name ?? ''));
            return 0;
        }

        $reason = Marketing\CampaignSafetyGuard::blockedReason($tenant, $c, $digits, 'enqueue');
        if ($reason !== null) {
            // The daily cap is paced by batchLimit; if it still surfaces here, stop rather than record
            // — the recipient will be reached on a later pass.
            if ($reason !== Marketing\CampaignSafetyGuard::R_DAILY_LIMIT) {
                $this->recordSkip($tenant, $c, $digits, $reason, (string) ($p->name ?? ''));
            }
            return 0;
        }

        if ($c->template_key) {
            $this->whatsapp->queueTemplate($tenant, $c->template_key, $digits, $vars, $opts + ['to_name' => $p->name]);
        } else {
            $text = $this->renderer->renderString($waText, array_merge($this->renderer->defaults($tenant), $vars), false);
            $this->whatsapp->queueRaw($tenant, $digits, $text, $opts + ['to_name' => $p->name]);
        }

        return 1;
    }

    /** Record a skipped recipient so the skip is auditable and not retried. */
    protected function recordSkip(Tenant $tenant, CommCampaign $c, string $digits, string $reason, string $name): void
    {
        \App\Models\CommMessage::create([
            'tenant_id'    => $tenant->id,
            'channel'      => 'whatsapp',
            'to_email'     => $digits,
            'to_name'      => $name !== '' ? $name : null,
            'status'       => \App\Models\CommMessage::ST_SKIPPED,
            'skip_reason'  => $reason,
            'related_type' => 'campaign',
            'related_id'   => $c->id,
            'meta'         => ['campaign' => $c->name, 'skipped' => $reason],
        ]);
    }

    /** Union audience (any of the campaign's channels reachable) past the cursor. */
    protected function cursorQuery(Tenant $tenant, CommCampaign $c): Builder
    {
        $seg = (array) $c->segment;
        $chs = $c->channels();

        if (count($chs) === 1) {
            return $this->audienceQuery($tenant, $seg, $chs[0])
                ->where('customer_profiles.id', '>', (int) $c->last_enqueued_id);
        }

        // both: base filters once, address requirement becomes (email OR phone)
        // This branch rebuilds the audience rather than calling audienceQuery(), so it needs the
        // discovered guard again — and it is the branch that actually enqueues messages.
        $q = DB::table('customer_profiles')->where('tenant_id', $tenant->id)
            ->whereRaw(\App\Services\Crm\BusinessStage::sql())
            ->where('customer_profiles.id', '>', (int) $c->last_enqueued_id)
            ->where(function ($w) {
                $w->where(function ($e) { $e->whereNotNull('email')->where('email', '!=', ''); })
                  ->orWhere(function ($p) { $p->whereNotNull('phone')->whereRaw('length(phone) >= 7'); });
            });
        Marketing\AudienceSegments::apply($q, $tenant, $seg);

        return $q;
    }

    /** Per-campaign per-channel dedupe against the message log itself. */
    protected function already(CommCampaign $c, string $channel, string $address): bool
    {
        return DB::table('comm_messages')
            ->where('tenant_id', $c->tenant_id)
            ->where('related_type', 'campaign')->where('related_id', $c->id)
            ->where('channel', $channel)->where('to_email', $address)
            ->exists();
    }

    /** Resolve inline content (template campaigns render inside the services). */
    protected function content(Tenant $tenant, CommCampaign $c): array
    {
        return [
            (string) ($c->subject ?: $c->name),
            (string) ($c->html_body ?: ''),
            (string) ($c->text_body ?: ''),
        ];
    }

    /** Guarantee an unsubscribe link on campaign email. */
    protected function withUnsub(string $html): string
    {
        if (str_contains($html, 'unsubscribe_url')) return $html;
        return $html.'<p style="font-size:11px;color:#9ca3af;margin-top:18px">'
            .'<a href="{{unsubscribe_url}}" style="color:#9ca3af">Unsubscribe</a> from these emails.</p>';
    }

    /** Live stats straight from the spine. */
    public function stats(CommCampaign $c): array
    {
        $base = DB::table('comm_messages')
            ->where('tenant_id', $c->tenant_id)
            ->where('related_type', 'campaign')->where('related_id', $c->id);

        $sent      = (clone $base)->where('status', 'sent')->count();
        $delivered = (clone $base)->whereNotNull('delivered_at')->count();
        $read      = (clone $base)->whereNotNull('opened_at')->count();     // WhatsApp read = opened_at
        $replied   = (clone $base)->whereNotNull('replied_at')->count();
        $failed    = (clone $base)->where('status', 'failed')->count();
        $skipped   = (clone $base)->where('status', 'skipped')->count();
        $optedOut  = (clone $base)->where('skip_reason', 'opted_out')->count();
        $enqueued  = (clone $base)->count();

        $rate = fn (int $num, int $den): float => $den > 0 ? round($num / $den * 100, 1) : 0.0;

        return [
            'enqueued'  => $enqueued,
            'sent'      => $sent,
            'delivered' => $delivered,
            'read'      => $read,
            'replied'   => $replied,
            'queued'    => (clone $base)->whereIn('status', ['pending', 'retry', 'sending'])->count(),
            'failed'    => $failed,
            'skipped'   => $skipped,
            'opted_out' => $optedOut,
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),   // mostly suppression hits
            'opened'    => $read,                                                  // back-compat alias
            'clicked'   => (clone $base)->where('clicks', '>', 0)->count(),
            'bounced'   => (clone $base)->whereNotNull('bounced_at')->count(),
            'unsubbed'  => (clone $base)->whereNotNull('unsubscribed_at')->count(),
            // Rates (percentages) — internal quality signals, NOT official WhatsApp thresholds.
            'delivery_rate' => $rate($delivered, $sent),
            'read_rate'     => $rate($read, $delivered ?: $sent),
            'reply_rate'    => $rate($replied, $sent),
            'failure_rate'  => $rate($failed, $sent + $failed),
            'opt_out_rate'  => $rate($optedOut, $enqueued),
        ];
    }
}
