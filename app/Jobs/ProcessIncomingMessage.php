<?php
namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Bot\BotBrain;
use App\Services\Bot\IntentRouter;
use App\Services\Bot\LeadService;
use App\Services\Bot\MarketingBrain;
use App\Services\Bot\VisionProductFinder;
use App\Models\CustomerProfile;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\BotTrace;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Generous attempts: WithoutOverlapping releases (not fails) while a sibling runs. */
    public int $tries = 25;
    public int $backoff = 3;

    /**
     * Reliability net (2026-07-18): if processing dies for ANY reason, the customer
     * still gets a courtesy reply and a human gets the alarm — silence is forbidden.
     */
    public function failed(\Throwable $e): void
    {
        try {
            $t = \App\Models\Tenant::find($this->tenantId);
            $from = preg_replace('/[^0-9]/', '', (string) ($this->incoming['from'] ?? ''));
            if ($t && $from !== '') {
                try {
                    app(\App\Services\WhatsApp\WhatsAppManager::class)->forTenant($t)
                        ->sendText($t->whatsapp_instance, $from,
                            "We received your message \u{1F64F} Give us one moment — a team member will reply shortly.");
                } catch (\Throwable $e2) {
                }
                \App\Jobs\NotifyOwner::dispatch($t->id,
                    "\u{1F6A8} Bot failed to process a message from {$from} — please reply manually. (".mb_substr($e->getMessage(), 0, 120).')');
            }
            \App\Support\BotTrace::log($this->tenantId, 'job_failed', $from ?? '', mb_substr(get_class($e), 0, 60), mb_substr($e->getMessage(), 0, 200));
        } catch (\Throwable $e3) {
        }
    }

    public function __construct(
        public int $tenantId,
        public string $driver,
        public array $incoming,
    ) {}

    /**
     * 2B — Conversation serialization. Only one message per conversation is
     * processed at a time; others release and retry, preserving order.
     */
    public function middleware(): array
    {
        $key = \App\Support\Idempotency::conversationLock(
            $this->tenantId,
            (string) ($this->incoming['instance'] ?? ''),
            (string) ($this->incoming['from'] ?? ''),
        );

        return [(new WithoutOverlapping($key))->releaseAfter(3)->expireAfter(30)];
    }

    public function handle(TenantContext $ctx, WhatsAppManager $wa, BotBrain $brain, MarketingBrain $marketing, VisionProductFinder $finder, IntentRouter $router, LeadService $leads): void
    {
        $ctx->set($this->tenantId);                 // scope everything to this tenant
        $tStart = microtime(true);                  // when this job started processing
        $tenant = Tenant::findOrFail($this->tenantId);

        // Which number should this REPLY go out on?
        //  1. the number the customer messaged (so the thread stays on their number), else
        //  2. if that's the primary and the watchdog has flagged the primary down (wa_send_instance
        //     points at a connected channel), reply from that connected number so the customer still
        //     gets answered during an outage.
        // Every send/markRead/media read uses $tenant->whatsapp_instance, so setting it here (in
        // memory only, never saved) routes the whole reply — no send-path changes needed.
        $primary   = (string) $tenant->whatsapp_instance;
        $inInst    = (string) ($this->incoming['instance'] ?? '');
        $replyInst = $primary;
        if ($inInst !== '' && $inInst !== $primary
            && \App\Models\WhatsappChannel::isTenantInstance($tenant->id, $inInst)) {
            $replyInst = $inInst;
        }
        if ($replyInst === $primary) {
            $fb = trim((string) $tenant->setting('wa_send_instance', ''));
            if ($fb !== '' && $fb !== $primary
                && \App\Models\WhatsappChannel::isTenantInstance($tenant->id, $fb)) {
                $replyInst = $fb;
            }
        }
        if ($replyInst !== $primary) {
            $tenant->whatsapp_instance = $replyInst;
        }

        $gateway = $wa->forTenant($tenant);

        $convo = Conversation::firstOrCreate(
            ['customer_phone' => $this->incoming['from'], 'instance' => $this->incoming['instance']],
            ['state' => [], 'cart' => []]
        );

        // 2A — Message idempotency. Claim this WhatsApp message id exactly once.
        // A duplicate delivery / queue retry that reaches here is dropped before it
        // can log, reply, or touch the cart. (Concurrency is already prevented by
        // the per-conversation lock in middleware(), so claiming here is safe.)
        $mid = (string) ($this->incoming['messageId'] ?? '');
        if ($mid !== '' && ! \App\Models\MessageReceipt::claim($this->tenantId, $convo->id, $mid)) {
            BotTrace::log($this->tenantId, (string) $mid, (string) $this->incoming['from'], 'skipped', 'duplicate message id');
            return;
        }

        // --- Marketing opt-out (STOP) + reply attribution, before any bot routing ---
        // Only customer messages; an owner's manual reply (from_me) is handled below.
        if (empty($this->incoming['from_me'])) {
            $from   = (string) $this->incoming['from'];
            $digits = preg_replace('/[^0-9]/', '', $from) ?: '';
            $body   = trim((string) ($this->incoming['text'] ?? ''));

            if ($body !== '' && \App\Services\Comms\Marketing\OptOutKeywords::isStop($body)) {
                \App\Services\Comms\Marketing\MarketingConsent::optOut($this->tenantId, $digits, 'inbound-stop');
                try {
                    $gateway->sendText($replyInst, $digits,
                        "You've been unsubscribed and won't receive marketing messages from us. "
                        . "You can still message us any time to place an order.");
                } catch (\Throwable $e) {
                    // A failed confirmation must not undo the opt-out — the suppression is already written.
                }
                BotTrace::log($this->tenantId, (string) $mid, $from, 'marketing_opt_out', 'inbound STOP: ' . mb_substr($body, 0, 40));
                return;   // an opt-out is not routed into the ordering bot
            }

            // Marketing opt-IN (SUBSCRIBE / JOIN / OPT IN) — the mirror of STOP. Records explicit consent
            // and confirms. recordOptIn deliberately does NOT lift a prior opt-out (that stays admin-only),
            // so an unsubscribed customer texting SUBSCRIBE is acknowledged but not silently re-enabled.
            if ($body !== '' && \App\Services\Comms\Marketing\OptInKeywords::isOptIn($body)) {
                \App\Services\Comms\Marketing\MarketingConsent::recordOptIn($tenant, $digits, 'inbound-optin');
                try {
                    $gateway->sendText($replyInst, $digits,
                        "You're subscribed ✅ — we'll send you occasional offers and restock alerts. "
                        . "Reply STOP any time to unsubscribe.");
                } catch (\Throwable $e) {
                    // A failed confirmation must not undo the opt-in — the consent stamp is already written.
                }
                BotTrace::log($this->tenantId, (string) $mid, $from, 'marketing_opt_in', 'inbound opt-in: ' . mb_substr($body, 0, 40));
                return;   // handled — not routed into the ordering bot
            }

            // Not a STOP — if we recently sent this number a marketing message, credit the reply.
            try {
                \App\Services\Comms\WhatsAppStatusIngest::recordReply($this->tenantId, $digits);
            } catch (\Throwable $e) {
                // Reply attribution is a metric, never allowed to break message handling.
            }
        }

        // Owner replied by hand (fromMe). If it's just the bot's own message echoing back,
        // ignore it. If it's something the owner typed, treat it as a manual takeover and
        // soft-pause auto-replies for this chat for a while, so the bot doesn't talk over a
        // live human conversation ("Good morning, I'll reach now" → "Ok let me come").
        if (! empty($this->incoming['from_me'])) {
            $txt   = trim((string) $this->incoming['text']);
            $state = is_array($convo->state) ? $convo->state : [];
            if ($txt !== '' && $this->norm($txt) !== ($state['lg_last_out'] ?? '')) {
                // A manually-typed reply logs into history (so the AI SEES it and stays consistent) —
                // then, by default, soft-pauses the bot so it doesn't talk over a live human. But if
                // the owner has turned this off (human_takeover_on_reply = false), a stray manual
                // message must NOT stop the AI: log it for context and let the bot keep replying.
                $pauseOnReply = (bool) $tenant->setting('human_takeover_on_reply', true);
                $full  = (int) $tenant->setting('human_takeover_minutes', 60);
                $nudge = (int) $tenant->setting('human_nudge_minutes', 3);
                // A short greeting ("hi") is a nudge to wake a silent bot, not a real takeover: pause
                // only briefly so the bot resumes for the customer's NEXT question. A substantive reply
                // — or a human already engaged in this chat — keeps the full takeover window.
                $alreadyEngaged = (int) ($state['human_until'] ?? 0) > time();
                $isNudge = \App\Support\AgentNudge::is($txt) && ! $alreadyEngaged;
                $mins = $isNudge ? $nudge : $full;
                if ($pauseOnReply && $mins > 0) {
                    $state['human_until'] = time() + $mins * 60;
                    $convo->state = $state;
                }
                $convo->last_message_at = now();
                $convo->save();
                MessageLog::record($this->tenantId, $this->incoming['from'], $this->incoming['instance'], 'out', 'human', $txt);
                BotTrace::log($this->tenantId, (string) ($this->incoming['trace'] ?? $mid), (string) $this->incoming['from'],
                    'manual_takeover', $pauseOnReply ? (($isNudge ? 'nudge' : 'takeover') . ' — bot paused ' . $mins . 'm') : 'logged — AI stays on (takeover-on-reply off)');
            }
            return; // never auto-reply to our own number
        }

        // Always log what the customer said — even if the bot is off or a human
        // has taken over. This is what powers the live web inbox.
        // WhatsApp-Web mode (2026-07-18): remember WHAT arrived, not just its text.
        // Purely additive — flow, bot behaviour and all existing fields unchanged; the
        // media itself stays on WhatsApp's servers and is fetched on demand by key.
        $mKey  = $this->incoming['media_key'] ?? null;
        $mMime = (string) ($this->incoming['media_mime'] ?? '');
        $mMeta = [];
        if ($mKey) {
            // Kind from the normalizer's explicit flags (mime is a fallback, not the source of truth).
            $kind = ! empty($this->incoming['has_image']) ? 'image'
                : (! empty($this->incoming['has_audio']) ? 'audio'
                : (! empty($this->incoming['has_video']) ? 'video'
                : (! empty($this->incoming['has_document']) ? 'file'
                : (str_starts_with($mMime, 'image/') ? 'image'
                : (str_starts_with($mMime, 'audio/') ? 'audio'
                : (str_starts_with($mMime, 'video/') ? 'video' : 'file'))))));
            $mMeta = ['media' => array_filter([
                'kind'    => $kind,
                'key'     => $mKey,
                'mime'    => $mMime ?: null,
                'name'    => trim((string) ($this->incoming['doc_name'] ?? '')) ?: null,
                'caption' => trim((string) ($this->incoming['media_caption'] ?? ($this->incoming['image_caption'] ?? ''))) ?: null,
            ])];
        } elseif (($this->incoming['lat'] ?? null) !== null && ($this->incoming['lng'] ?? null) !== null) {
            // Location pin: no media key — coordinates ARE the payload. Gold for deliveries.
            $mMeta = ['media' => array_filter([
                'kind' => 'location',
                'lat'  => (float) $this->incoming['lat'],
                'lng'  => (float) $this->incoming['lng'],
                'name' => trim((string) ($this->incoming['loc_name'] ?? '')) ?: null,
                'address' => trim((string) ($this->incoming['loc_address'] ?? '')) ?: null,
            ])];
        }
        $inMsg = MessageLog::record(
            $this->tenantId, $this->incoming['from'], $this->incoming['instance'],
            'in', 'customer', $this->incoming['text'], $this->incoming['messageId'] ?: null,
            null, $mMeta
        );

        // Auto-translate (2026-07-18): Juba customers write Arabic, the team reads
        // English — translate AT ARRIVAL so the inbox is readable without any tap.
        // Fail-soft (webhook can never break on it); cached forever on the row;
        // per-tenant switch chat_auto_translate (default ON).
        try {
            if ((bool) $tenant->setting('chat_auto_translate', true)
                && \App\Services\Chat\ChatTranslator::hasArabic((string) $this->incoming['text'])) {
                app(\App\Services\Chat\ChatTranslator::class)->translateAndCache($inMsg);
            }
        } catch (\Throwable $e) {
            // the 🌐 button remains as manual fallback
        }

        // v4.43 — a plain "yes" against an order confirmation we sent is the customer signing for
        // it. Recorded here, BEFORE burst-coalescing, for the same reason email capture is: an
        // acceptance sitting in a message that a later one supersedes must not be thrown away.
        //
        // Deliberately silent and never blocking — the bot still answers normally. The matcher
        // (OrderConfirmationService::looksLikeYes) refuses anything long or carrying a negation or
        // a change word, because "yes but make it 5" is not agreement and inventing evidence would
        // be worse than having none: the team would walk into the dispute believing they were covered.
        try {
            \App\Services\Sales\OrderConfirmationService::noteInboundYes(
                $this->tenantId, (string) $this->incoming['from'], (string) $this->incoming['text']
            );
        } catch (\Throwable $e) {
            // an order confirmation stamp must never cost us an inbound message
        }

        if ($this->incoming['messageId']) {
            $gateway->markRead($tenant->whatsapp_instance, $this->incoming['messageId']);
        }

        // Should the bot answer automatically?
        //  - 'auto'  : bot replies (default)
        //  - anything else (e.g. 'off'/'monitor') : log only, a human handles it
        //  - agent_active : a staff member has taken this chat over from the web
        $trace = (string) ($this->incoming['trace'] ?? ($this->incoming['messageId'] ?: 'm_?'));
        $from  = (string) $this->incoming['from'];
        BotTrace::log($this->tenantId, $trace, $from, 'started');

        // Email capture (v4.7.4, RA International fix): a customer sending their email address is
        // GIVING it to us — save it to their profile, and when they also ask for "the details" /
        // price list / catalogue, raise an AI Follow-up so the team actually emails the documents.
        // Deterministic and silent; the AI's spoken reply is handled by its own prompt rules.
        // Runs BEFORE burst-coalescing so an email in a superseded message is never lost.
        try {
            $txtIn = (string) $this->incoming['text'];
            if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', $txtIn, $em)) {
                $email = mb_strtolower($em[0]);
                $prof = \App\Models\CustomerProfile::firstOrCreate(
                    ['tenant_id' => $this->tenantId, 'phone' => preg_replace('/[^0-9]/', '', $from)],
                    []
                );
                if (trim((string) $prof->email) === '') {
                    $prof->email = $email;
                    $prof->save();
                    BotTrace::log($this->tenantId, $trace, $from, 'email_captured', $email);
                } elseif (mb_strtolower(trim((string) $prof->email)) !== $email) {
                    $prof->notes = trim((string) $prof->notes . "\nAlt email given on WhatsApp: " . $email);
                    $prof->save();
                    BotTrace::log($this->tenantId, $trace, $from, 'email_captured', 'alt: ' . $email);
                }
            }
            // Details-intent: a share/send verb + a documents noun (this message), with an email known
            // (this message or already on the profile) → follow-up proposal for the team to email docs.
            $wantsDocs = preg_match('/\b(share|send|give|forward|mail|email|provide|need|want)\b/iu', $txtIn)
                && preg_match('/\b(details?|price\s*lists?|prices?|pricelists?|catalogues?|catalogs?|profiles?|quotations?|quotes?|documents?|docs?|information|info)\b/iu', $txtIn);
            if ($wantsDocs) {
                $prof = $prof ?? \App\Models\CustomerProfile::where('tenant_id', $this->tenantId)
                    ->where('phone', preg_replace('/[^0-9]/', '', $from))->first();
                $knownEmail = isset($em[0]) ? mb_strtolower($em[0]) : mb_strtolower(trim((string) ($prof->email ?? '')));
                if ($knownEmail !== '') {
                    $dup = \App\Models\FollowupProposal::where('tenant_id', $this->tenantId)
                        ->where('customer_phone', $from)->where('trigger', 'email_request')
                        ->whereIn('status', ['draft', 'pending', 'approved'])
                        ->where('created_at', '>=', now()->subDays(7))->exists();
                    if (! $dup) {
                        \App\Models\FollowupProposal::create([
                            'tenant_id' => $this->tenantId, 'customer_phone' => $from,
                            'source' => 'bot', 'trigger' => 'email_request',
                            'trigger_reason' => 'Customer shared ' . $knownEmail . ' and asked for details/documents by email',
                            'evidence' => mb_substr($txtIn, 0, 300),
                            'draft' => 'Email our catalogue / price list / company profile to ' . $knownEmail . ' (they asked on WhatsApp).',
                            'confidence' => 0.9, 'status' => 'draft',
                        ]);
                        BotTrace::log($this->tenantId, $trace, $from, 'email_followup_created', $knownEmail);
                    }
                }
            }
        } catch (\Throwable $e) { /* capture must never break the pipeline */ }

        // Burst coalescing (v4.7.4): customers often send two rapid lines ("jjayakumar@…" then
        // "Pls share the details") and the bot used to answer each one separately. The webhook
        // stamps every plain-text message with its arrival sequence and delays the job a few
        // seconds; here, if a NEWER text from this customer has already arrived, this message
        // stays silent — it is logged above (so the newest reply sees it in history) and the
        // newest message answers the whole burst once.
        $mySeq = (int) ($this->incoming['coal_seq'] ?? 0);
        if ($mySeq > 0) {
            $ck = 'coal:' . $this->tenantId . ':' . preg_replace('/[^0-9]/', '', $from);
            if ((int) \Illuminate\Support\Facades\Cache::get($ck, $mySeq) > $mySeq) {
                BotTrace::log($this->tenantId, $trace, $from, 'coalesced', 'superseded by a newer message in the burst');
                return;
            }
        }

        // Staff lead claim: "CLAIM 1045" from a configured recipient → atomic first-wins.
        // Handled before the auto-reply gates so it works even when the bot is off.
        if (preg_match('/^\s*claim\s+#?(\d+)\b/i', (string) $this->incoming['text'], $cm)) {
            $cr = $leads->claim($tenant, (int) $cm[1], $from);
            if ($cr !== null) {
                $gateway->sendText($tenant->whatsapp_instance, $from, $cr);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'system', $cr);
                BotTrace::log($this->tenantId, $trace, $from, 'lead_claim_cmd', $cm[1]);
                return;
            }
        }

        // Buyer replies the Juba price for a sourcing request: "<id> <price>" (e.g. "12 45000").
        // Handled before the bot gates so it works even when the bot is off. Only fires for the
        // configured buyer number and a real open request, so it can't catch normal messages.
        try {
            $buyerPhone = preg_replace('/[^0-9]/', '', (string) $tenant->setting('sourcing_buyer_phone', ''));
            if ($buyerPhone !== '' && preg_replace('/[^0-9]/', '', (string) $from) === $buyerPhone
                && preg_match('/^\s*(?:price\s+)?#?(\d+)\s+([0-9][0-9,]*(?:\.[0-9]+)?)\s*([a-zA-Z]{3})?\s*$/', (string) $this->incoming['text'], $mm)) {
                $sr = \App\Models\SourcingRequest::where('tenant_id', $tenant->id)->where('id', (int) $mm[1])->first();
                if ($sr && $sr->status === 'new') {
                    $price = (float) str_replace(',', '', $mm[2]);
                    if ($price > 0) {
                        $sr->quoted_price = round($price, 2);
                        $cur = strtoupper((string) ($mm[3] ?? ''));
                        if (in_array($cur, ['UGX', 'USD', 'SSP'], true)) $sr->currency = $cur;
                        elseif (! $sr->currency) $sr->currency = 'UGX';
                        $sr->priced_by = trim((string) $tenant->setting('sourcing_buyer_name', '')) ?: 'Buyer';
                        $sr->priced_at = now();
                        $sr->status = 'priced';
                        $sr->save();
                        $reply = "\u{2705} Saved: {$sr->item_name} = {$sr->currency} " . number_format($price) . " (SR-{$sr->id}). Thank you!";
                        $gateway->sendText($tenant->whatsapp_instance, $from, $reply);
                        try { MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'system', $reply); } catch (\Throwable $e) {}
                        // Tell the person who asked (Asish) the price is in.
                        try { \App\Http\Controllers\Panel\SourcingController::notifyRequester($tenant, $sr); } catch (\Throwable $e) {}
                        BotTrace::log($this->tenantId, $trace, $from, 'sourcing_priced', 'SR-' . $sr->id . ' = ' . $price);
                        return;
                    }
                }
            }
        } catch (\Throwable $e) { /* never break message handling */ }

        $botMode = (string) $tenant->setting('bot_mode', 'auto');
        $convo->refresh();
        if ($convo->agent_active) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'a person is handling this chat (Take over)');
            return;
        }
        if ((int) (is_array($convo->state) ? ($convo->state['human_until'] ?? 0) : 0) > time()) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'owner is handling this chat (recent manual reply)');
            return;
        }

        // ---- Facebook-ad awareness (gated, best-effort — NEVER breaks the bot) ----------
        // When a customer arrives from / forwards one of our Facebook ads, weave the advertised
        // product into their message so every brain (ai / n8n / inbuilt) answers about THAT
        // product instead of "it varies by product…". Toggle off with setting bot_ad_awareness=0.
        try {
            if ((bool) $tenant->setting('bot_ad_awareness', true)) {
                $adHead = trim((string) ($this->incoming['ad_headline'] ?? ''));
                if ($adHead === '' && ! empty($this->incoming['is_forwarded']) && ! empty($this->incoming['has_image'])) {
                    $adHead = trim((string) ($this->incoming['image_caption'] ?? ''));   // forwarded ad image
                }
                $adHead = trim(preg_replace('/\s+/', ' ', (string) $adHead));
                $q = trim((string) $this->incoming['text']);
                // The ad TITLE is often our page / campaign / brand name (e.g. "SkyTrips"), NOT a
                // product we stock. Treating it as a product makes the bot reply "I don't have
                // information on SkyTrips" and ignore the customer's real question. So: if the title
                // is a known page/brand name (ad_ignore_titles setting, or our own business name),
                // DON'T weave it as a product — just let the customer's real message through.
                if ($adHead !== '' && $this->adTitleIsBrand($tenant, $adHead)) {
                    BotTrace::log($this->tenantId, $trace, $from, 'ad_referral', $adHead . ' (page/brand — not treated as product)');
                    // Red-carpet (2026-07-19): a page-name title still means THIS IS AN AD CLICK —
                    // the hottest lead type. Tag it once so the AI runs the AD LEAD playbook
                    // (welcome + concrete answer + catalogue via <<SEND>> + one qualifying question).
                    $st = is_array($convo->state) ? $convo->state : [];
                    if (empty($st['ad_lead_tagged'])) {
                        $st['ad_lead_tagged'] = time();
                        $convo->state = $st;
                        $convo->save();
                        $this->incoming['text'] = '[NEW AD LEAD] ' . trim((string) $this->incoming['text']);
                        \App\Support\MessageLog::record($this->tenantId, $from, (string) $tenant->whatsapp_instance,
                            'in', 'system', '[lead source: WhatsApp/Facebook ad — ' . mb_substr($adHead, 0, 60) . ']');
                    }
                    $adHead = '';   // the customer's real question flows through untouched
                }
                if ($adHead !== '') {
                    $adHead = mb_substr($adHead, 0, 160);
                    $st = is_array($convo->state) ? $convo->state : [];
                    $st['ad_topic'] = $adHead; $st['ad_topic_at'] = time();
                    $convo->state = $st; $convo->save();
                    $this->incoming['text'] = $q !== ''
                        ? ('About our advertised product "' . $adHead . '": ' . $q)
                        : ('Tell me about "' . $adHead . '"');
                    BotTrace::log($this->tenantId, $trace, $from, 'ad_referral', $adHead);
                } elseif ($q !== '') {
                    // Bare, context-less follow-up (e.g. "MOQ?", "price?") — reattach the recent
                    // ad topic. Strict patterns only, so it never hijacks a real product query.
                    $st = is_array($convo->state) ? $convo->state : [];
                    if (! empty($st['ad_topic']) && (time() - (int) ($st['ad_topic_at'] ?? 0) < 1800)
                        && (preg_match('/^\s*(what(?:\'?s|\s+is)\s+the\s+)?(moq|min(?:imum)?\s*order(\s*(qty|quantity))?|price|cost|rate)\s*\??\s*$/i', $q)
                            || preg_match('/^\s*how\s+much\s*\??\s*$/i', $q)
                            || preg_match('/^\s*(is\s+it\s+|are\s+they\s+)?(available|in\s+stock)\s*\??\s*$/i', $q))) {
                        $this->incoming['text'] = 'About "' . $st['ad_topic'] . '": ' . $q;
                        BotTrace::log($this->tenantId, $trace, $from, 'ad_followup', $st['ad_topic']);
                    }
                }
            }
        } catch (\Throwable $e) { /* ad-awareness must never break message handling */ }

        // ---- Native AI brain (bot_mode = ai) — same behaviour as n8n, no external workflow ----
        if ($botMode === 'ai') {
            $mutedDigits = array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), (array) $tenant->setting('bot_muted', []));
            if (in_array($from, $mutedDigits, true)) {
                BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'number is muted (no auto-reply)');
                return;
            }
            $aiText = (string) $this->incoming['text'];

            // Voice note → transcribe, then treat as text.
            if (trim($aiText) === '' && ! empty($this->incoming['has_audio'] ?? false)) {
                $b64 = (string) ($this->incoming['audio_b64'] ?? '');
                if ($b64 === '' && ! empty($this->incoming['media_key'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
                    $b64 = (string) ($gateway->getMediaBase64($tenant->whatsapp_instance, $this->incoming['media_key']) ?? '');
                }
                $vt = new \App\Services\Bot\VoiceTranscriber();
                if ($vt->enabled() && $b64 !== '') {
                    $tr = (string) $vt->transcribe($b64);
                    if (trim($tr) !== '') { $aiText = trim($tr); $this->incoming['text'] = $aiText; BotTrace::log($this->tenantId, $trace, $from, 'voice_transcribed', mb_substr($aiText, 0, 90)); }
                }
            }

            // Image → match it against our real catalogue, then hand the bytes to the AI (vision).
            $imageB64 = '';
            $photoMatch = null;
            if (! empty($this->incoming['has_image'] ?? false)) {
                // A photo dump must not get a reply per photo. First work out WHO is sending:
                //  • a SUPPLIER offering goods (their number is in Suppliers, or selling words in the
                //    caption) → one "we'll review" reply + an owner alert, then silence.
                //  • a BUYER → one "which items?" nudge, then silence.
                // Either way we answer ONCE per burst (rolling 40s window) and stay quiet after.
                $cap    = trim((string) ($this->incoming['image_caption'] ?? ''));
                $digits = (string) preg_replace('/[^0-9]/', '', (string) $from);
                $tail   = strlen($digits) >= 9 ? substr($digits, -9) : $digits;

                $isSupplier = false;
                try {
                    if ($tail !== '') {
                        foreach (\App\Models\Supplier::where('tenant_id', $tenant->id)->whereNotNull('phone')->pluck('phone') as $ph) {
                            $pd = (string) preg_replace('/[^0-9]/', '', (string) $ph);
                            if ($pd !== '' && str_contains($pd, $tail)) { $isSupplier = true; break; }
                        }
                    }
                } catch (\Throwable $e) { /* detection is best-effort */ }
                if (! $isSupplier) {
                    $blob = mb_strtolower($cap . ' ' . $aiText);
                    if (preg_match('/\b(we sell|we supply|supplier|distributor|wholesaler?|manufacturer|we deal|selling to you|sell (?:to|on) (?:you|your)|supply (?:to )?you|price list|catalog(?:ue)?|offer you|we are selling|sell on your platform|do you (?:buy|import|stock these))\b/', $blob)) {
                        $isSupplier = true;
                    }
                }

                $burstKey = 'imgburst:' . $this->tenantId . ':' . $from;
                $seen = (int) \Illuminate\Support\Facades\Cache::get($burstKey, 0) + 1;
                \Illuminate\Support\Facades\Cache::put($burstKey, $seen, now()->addSeconds(40));

                if ($isSupplier) {
                    if ($seen === 1) {
                        $reply = trim((string) $tenant->setting('image_supplier_reply',
                            "🙏 Thank you for sharing these! I've passed them to our purchasing team — they'll review your items and get back to you about pricing and stocking."));
                        if ($reply !== '') {
                            $gateway->sendText($tenant->whatsapp_instance, $from, $reply);
                            MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $reply);
                        }
                        try {
                            NotifyOwner::dispatch($this->tenantId, "🧾 A supplier (+{$from}) is offering products — they sent product photos on WhatsApp. Open Chats to review and reply.");
                        } catch (\Throwable $e) { /* alert best-effort */ }
                        BotTrace::log($this->tenantId, $trace, $from, 'supplier_offer', 'photos from a supplier — team notified');
                    } else {
                        BotTrace::log($this->tenantId, $trace, $from, 'supplier_offer', "suppressed photo #{$seen}");
                    }
                    return;
                }

                // Buyer: only nudge/suppress an UNCAPTIONED dump (a single captioned photo is a real question).
                if ($cap === '') {
                    if ($seen === 2) {
                        $nudge = trim((string) $tenant->setting('image_burst_reply',
                            "📷 I see you've sent a few photos! Tell me which items you'd like and how many, and I'll put the order together for you."));
                        if ($nudge !== '') {
                            $gateway->sendText($tenant->whatsapp_instance, $from, $nudge);
                            MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $nudge);
                        }
                        BotTrace::log($this->tenantId, $trace, $from, 'image_burst', 'nudged on 2nd photo');
                        return;
                    }
                    if ($seen >= 3) {
                        BotTrace::log($this->tenantId, $trace, $from, 'image_burst', "suppressed photo #{$seen}");
                        return;
                    }
                }

                $imageB64 = (string) ($this->incoming['image_b64'] ?? '');
                if ($imageB64 === '' && ! empty($this->incoming['media_key'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
                    $imageB64 = (string) ($gateway->getMediaBase64($tenant->whatsapp_instance, $this->incoming['media_key']) ?? '');
                }
                if (trim($aiText) === '') $aiText = (string) ($this->incoming['image_caption'] ?? '');

                // v4.24 — "do we actually sell what is in this photo?" The rules brain has checked
                // photos against the real catalogue since v4.4; on the AI brain the picture went
                // straight to the vision model, which could describe it beautifully and quote a price
                // for something we do not stock. Match FIRST, then let the AI write the sentence.
                if ($imageB64 !== '') {
                    try {
                        $photoMatch = app(\App\Services\Bot\PhotoCatalogueMatch::class)
                            ->match($tenant, $imageB64, (string) ($this->incoming['image_caption'] ?? ''), $trace, $from);
                    } catch (\Throwable $e) {
                        $photoMatch = null;
                    }
                }
            }

            $ok = app(\App\Services\Bot\AiBrain::class)->handle(
                $tenant, $convo, $from, $aiText, $gateway,
                ['imageB64' => $imageB64, 'mime' => (string) ($this->incoming['media_mime'] ?? 'image/jpeg'),
                 'photoMatch' => $photoMatch ?? null,
                 // Quoted-reply context (2026-07-30): when the customer taps Reply on one of our
                 // earlier messages (product push, quotation, …), the AI must know WHICH message —
                 // otherwise "when will you deliver it" has no referent.
                 'quoted' => (string) ($this->incoming['quoted_text'] ?? '')]
            );
            BotTrace::log($this->tenantId, $trace, $from, $ok ? 'ai_replied' : 'ai_no_reply');
            return;
        }

        // ---- Pluggable n8n brain ---------------------------------------------------
        // CloudBSS stays the only system that talks to WhatsApp. For an n8n tenant we hand
        // the message to the shared n8n workflow and stop; n8n posts its reply back to
        // /api/bot/reply, which sends + logs it exactly like an inbuilt reply.
        if ($botMode === 'n8n') {
            $mutedDigits = array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), (array) $tenant->setting('bot_muted', []));
            if (in_array($from, $mutedDigits, true)) {
                BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'number is muted (no auto-reply)');
                return;
            }
            $this->forwardToN8n($tenant, $convo, $from, (string) $this->incoming['text'], $trace);
            return;
        }

        // Inbuilt grocery/cart bot runs for 'auto' (legacy on) or 'inbuilt'; anything else = off.
        if (! in_array($botMode, ['auto', 'inbuilt'], true)) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'bot is switched off');
            return;
        }
        $muted = (array) $tenant->setting('bot_muted', []);
        $mutedDigits = array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), $muted);
        if (in_array($from, $mutedDigits, true)) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'number is muted (no auto-reply)');
            return;
        }

        $text  = (string) $this->incoming['text'];
        $state = is_array($convo->state) ? $convo->state : [];
        $now   = time();

        // Status reply: the customer replied to our WhatsApp Status, so the bare text
        // ("1 Dish") is meaningless on its own. Rebuild it with the status caption (e.g.
        // "Today special lunch menu") so the bot routes it to the menu instead of doing
        // a product search and saying "we don't stock 1 dish". No caption → show the menu.
        if (! empty($this->incoming['is_status_reply'])) {
            $q = trim((string) ($this->incoming['quoted_text'] ?? ''));
            $text = $q !== '' ? trim($q . ' ' . $text) : 'menu';
            BotTrace::log($this->tenantId, $trace, $from, 'status_reply', 'reading as: ' . $text);
        }

        // ---- Loop guard: stop runaway bot-to-bot / echo conversations ----
        // 1) Echo: the incoming message is identical to what we just sent.
        if (! empty($state['lg_last_out']) && $this->norm($text) !== '' && $this->norm($text) === $state['lg_last_out']) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'echo of our own last message');
            return;
        }
        // 2) Debounce: we already replied to this chat under 2s ago.
        if (! empty($state['lg_last_out_at']) && ($now - (int) $state['lg_last_out_at']) < 2) {
            BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'debounced (replied <2s ago)');
            return;
        }
        // 3) Rate breaker: too many auto-replies in a short window = likely a loop.
        $outTimes = array_values(array_filter(
            is_array($state['lg_out_times'] ?? null) ? $state['lg_out_times'] : [],
            fn ($t) => ($now - (int) $t) <= 600
        ));
        $in45 = count(array_filter($outTimes, fn ($t) => ($now - (int) $t) <= 45));
        if ($in45 >= 5 || count($outTimes) >= 12) {
            $convo->agent_active   = true;        // pause auto-reply on this chat
            $state['lg_paused']    = true;
            $state['lg_out_times'] = $outTimes;
            $alerted               = ! empty($state['lg_alerted']);
            $state['lg_alerted']   = true;
            $convo->state = $state;
            $convo->save();
            if (! $alerted) {
                NotifyOwner::dispatch($this->tenantId,
                    "⚠️ Possible message loop with +{$this->incoming['from']} — auto-reply paused for this chat. Open Chats to take over or switch the bot back on.");
            }
            BotTrace::log($this->tenantId, $trace, $from, 'paused', "loop guard: {$in45} replies in 45s");
            Log::warning('bot.loop_paused', [
                'tenant' => $this->tenantId,
                'from'   => $this->incoming['from'],
                'in45s'  => $in45,
                'in600s' => count($outTimes),
            ]);
            return;
        }

        $tBrain = microtime(true);

        // ---- Voice notes: transcribe, then run the normal pipeline ----
        // Many customers (especially older Gujarati ones) send a voice note instead of typing.
        // Fetch + transcribe it and feed the transcript through the same flow as typed text.
        // If transcription is off/unavailable/fails, never leave them hanging: acknowledge and
        // hand the chat to the shop.
        if (! empty($this->incoming['has_audio'] ?? false) && trim($text) === '' && ! $tenant->isMarketing()) {
            BotTrace::log($this->tenantId, $trace, $from, 'voice_received');
            $transcript = null;
            if ((bool) $tenant->setting('feature_voice_orders', true)) {
                $b64 = (string) ($this->incoming['audio_b64'] ?? '');
                if ($b64 === '' && ! empty($this->incoming['media_key'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
                    $b64 = (string) ($gateway->getMediaBase64($tenant->whatsapp_instance, $this->incoming['media_key']) ?? '');
                }
                $vt = new \App\Services\Bot\VoiceTranscriber();
                if ($vt->enabled() && $b64 !== '') $transcript = $vt->transcribe($b64);
            }
            if ($transcript !== null && trim($transcript) !== '') {
                BotTrace::log($this->tenantId, $trace, $from, 'voice_transcribed', mb_substr($transcript, 0, 90));
                $text = trim($transcript);
                $this->incoming['text'] = $text;
                // fall through to the normal text pipeline below
            } else {
                $ack = "\u{1F3A4} Got your voice note — the shop will reply here shortly. You can also type your order (e.g. \"2 thali, sev 250gm\") and I will take it right away.";
                $gateway->sendText($tenant->whatsapp_instance, $from, $ack);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $ack);
                NotifyOwner::dispatch($this->tenantId, "\u{1F3A4} +{$from} sent a voice note the bot could not read — open Chats to listen and reply.");
                BotTrace::log($this->tenantId, $trace, $from, 'voice_unhandled');
                return;
            }
        }

        // ---- Status Intelligence: an owner's menu poster / status image is the source of truth ----
        // An authorized owner sending an image is publishing today's offer, not searching the
        // catalogue. OCR + AI extract it and store it as the Active Daily Offer; customers asking
        // for the menu/thali/special then get it automatically. If it can't be read as an offer,
        // fall through to normal handling.
        if (! empty($this->incoming['has_image'] ?? false)
            && \App\Services\Bot\Merchant\MerchantDirectory::isAuthorized($tenant, $from)) {
            $ob64 = (string) ($this->incoming['image_b64'] ?? '');
            if ($ob64 === '' && ! empty($this->incoming['media_key'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
                $ob64 = (string) ($gateway->getMediaBase64($tenant->whatsapp_instance, $this->incoming['media_key']) ?? '');
            }
            $ocap   = (string) ($this->incoming['image_caption'] ?? '');
            $source = \App\Services\Bot\Offers\ActivitySource::classify(
                ! empty($this->incoming['is_status_post'] ?? false),
                ! empty($this->incoming['is_forwarded'] ?? false),
                true
            );
            $reply = null;
            try {
                $reply = app(\App\Services\Bot\Offers\ActivityIngestor::class)
                    ->ingestOwnerImage($tenant, $ob64, $ocap, $source);
            } catch (\Throwable $e) {
                BotTrace::log($this->tenantId, $trace, $from, 'offer_ingest_error', $e->getMessage());
            }
            if ($reply !== null && trim($reply) !== '') {
                $gateway->sendText($tenant->whatsapp_instance, $from, $reply);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'system', $reply);
                BotTrace::log($this->tenantId, $trace, $from, 'offer_captured', $source);
                $convo->save();
                return;
            }
        }

        // A status post that wasn't ingested as an owner offer (non-owner author, or an owner
        // status that isn't a readable menu) is dropped here — a status is never product-searched
        // or answered like a 1:1 customer message.
        if (! empty($this->incoming['is_status_post'] ?? false)) {
            BotTrace::log($this->tenantId, $trace, $from, 'status_ignored', 'not an owner offer');
            return;
        }

        // ---- Owner intent: a short owner TEXT updates live business state ----
        // The activity learner scores the message and either auto-applies, asks the owner to
        // confirm, or ignores it (letting the owner keep using the bot normally). Handles both
        // structured phrases and natural ones ("Fresh jalebi 😍", "Lunch started").
        if (empty($this->incoming['has_image'] ?? false)
            && trim((string) ($this->incoming['text'] ?? '')) !== ''
            && \App\Services\Bot\Merchant\MerchantDirectory::isAuthorized($tenant, $from)) {
            try {
                $res = app(\App\Services\Bot\Offers\OwnerActivityService::class)
                    ->process($tenant, $convo, (string) $this->incoming['text']);
                if (! empty($res['consume'])) {
                    $reply = (string) ($res['reply'] ?? '');
                    if ($reply !== '') {
                        $gateway->sendText($tenant->whatsapp_instance, $from, $reply);
                        MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'system', $reply);
                    }
                    BotTrace::log($this->tenantId, $trace, $from, 'owner_update', mb_substr((string) $this->incoming['text'], 0, 60));
                    $convo->save();
                    return;
                }
            } catch (\Throwable $e) {
                BotTrace::log($this->tenantId, $trace, $from, 'owner_update_error', $e->getMessage());
            }
        }

        // ---- Image search: customer sent a product photo instead of typing ----
        // Vision turns the photo (+ any caption) into a catalogue query; we verify the
        // query actually matches a product in THIS shop before replying (so a
        // hallucinated brand never reaches the customer), then let the normal product
        // search + numbered pick-list + cart flow handle it.
        $fromImage = false;
        $imgHash = ''; $imgQuery = ''; $imgConf = 0; $imgCandIds = [];
        if (! empty($this->incoming['has_image'] ?? false) && trim($text) === '' && ! $tenant->isMarketing()) {
            BotTrace::log($this->tenantId, $trace, $from, 'photo_received');
            $askName = "📷 Please type the product name (e.g. \"rice 5kg\") and I'll find it for you.";

            if (! (bool) $tenant->setting('feature_image_search', true)) {
                $gateway->sendText($tenant->whatsapp_instance, $from, $askName);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $askName);
                BotTrace::log($this->tenantId, $trace, $from, 'photo_unidentified', 'feature off');
                $convo->save();
                return;
            }

            $b64 = (string) ($this->incoming['image_b64'] ?? '');
            if ($b64 === '' && ! empty($this->incoming['media_key'] ?? null) && method_exists($gateway, 'getMediaBase64')) {
                $b64 = (string) ($gateway->getMediaBase64($tenant->whatsapp_instance, $this->incoming['media_key']) ?? '');
            }
            $imgHash = $b64 !== '' ? sha1($b64) : '';

            // Known-image shortcut: if customers have repeatedly chosen the same product
            // for THIS exact photo, skip OpenAI entirely and go straight to it. Works even
            // with no AI key. Self-correcting — a genuine new pick still gets recorded.
            $known = $imgHash !== '' ? $this->knownImageProduct($tenant, $imgHash) : null;

            if ($known) {
                $imgQuery   = $known['name'];
                $imgConf    = 100;
                $imgCandIds = [(string) $known['product_id']];
                $text       = $known['name'];
                $fromImage  = true;
                BotTrace::log($this->tenantId, $trace, $from, 'photo_known', "{$known['name']} ({$known['votes']}/{$known['total']})");
            } elseif (! $finder->enabled()) {
                $gateway->sendText($tenant->whatsapp_instance, $from, $askName);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $askName);
                BotTrace::log($this->tenantId, $trace, $from, 'photo_unidentified', 'no AI key');
                $convo->save();
                return;
            } else {
                $caption = (string) ($this->incoming['image_caption'] ?? '');
                $res     = $b64 !== '' ? $finder->identify($b64, $caption) : null;
                $minConf = (int) $tenant->setting('image_search_min_confidence', 50);
                $query   = $res['query'] ?? '';
                $conf    = (int) ($res['confidence'] ?? 0);

                // Couldn't read it, or vision isn't confident enough → ask for the name.
                if ($query === '' || $conf < $minConf) {
                    $miss = "📷 I couldn't make out the product in that photo. Please type the name (e.g. \"sugar 2kg\") and I'll find it.";
                    $gateway->sendText($tenant->whatsapp_instance, $from, $miss);
                    MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $miss);
                    BotTrace::log($this->tenantId, $trace, $from, 'photo_unidentified',
                        $query === '' ? 'vision returned nothing' : "low confidence {$conf}<{$minConf}: {$query}");
                    $convo->save();
                    return;
                }
                BotTrace::log($this->tenantId, $trace, $from, 'photo_identified', "{$query} ({$conf}%)");

                // Hallucination guard: the query must match a real product in this shop.
                $cands = $brain->searchCatalogue($tenant, $query);
                if (empty($cands)) {
                    $nf = "🙂 I couldn't find this in our store. Please type the product name and I'll check for you.";
                    $gateway->sendText($tenant->whatsapp_instance, $from, $nf);
                    MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $nf);
                    BotTrace::log($this->tenantId, $trace, $from, 'photo_search_miss', $query);
                    $convo->save();
                    return;
                }
                BotTrace::log($this->tenantId, $trace, $from, 'photo_search_hit', $query);

                // Remember the photo's identity + candidate products so we can label the
                // product the customer actually picks (feedback loop / training data).
                $imgCandIds = array_values(array_filter(array_map(
                    fn ($p) => (string) ($p['id'] ?? ''), array_slice($cands, 0, 8)
                )));
                $imgQuery = $query;
                $imgConf  = $conf;
                $text     = $query;
                $fromImage = true;
            }
        }

        // Intent routing: divert clear sales-interest / service-problem messages to the
        // lead pipeline instead of the shopping brain. Image-derived product queries and
        // marketing tenants are left alone.
        if (! $fromImage && ! $tenant->isMarketing()) {
            // Business Opportunity Engine: a sticky B2B qualification flow that takes over
            // from order-taking on distributor/wholesale/export/corporate/tender signals and
            // stays in control until the lead is qualified and handed to sales. Runs BEFORE
            // the one-shot lead capture so an in-progress opportunity never relapses into
            // "how many cartons?".
            $opp = app(\App\Services\Bot\BusinessOpportunityEngine::class);
            if ($opp->enabled($tenant)) {
                $oType = $opp->isActive($convo) ? null : $opp->detect($tenant, $text);
                if ($opp->isActive($convo) || $oType !== null) {
                    $oName  = (string) (CustomerProfile::where('phone', $from)->value('name') ?? '');
                    $oReply = $opp->handle($tenant, $convo, $from, $text, $oName ?: null, $oType);
                    if ($oReply !== '') {
                        $gateway->sendText($tenant->whatsapp_instance, $from, $oReply);
                        MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $oReply);
                        BotTrace::log($this->tenantId, $trace, $from, 'routed_opportunity', mb_substr($text, 0, 80));
                        $st = is_array($convo->state) ? $convo->state : [];
                        $st['lg_last_out'] = $this->norm($oReply);
                        $st['lg_last_out_at'] = time();
                        $convo->state = $st;
                        $convo->last_message_at = now();
                        $convo->save();
                        return;
                    }
                }
            }

            $intent = $router->classify($tenant, $text);
            if ($intent === 'lead' || $intent === 'ticket') {
                $name = (string) (CustomerProfile::where('phone', $from)->value('name') ?? '');
                $res  = $leads->capture($tenant, $from, $name ?: null, $text, $intent, 'whatsapp', $convo->id);
                $reply = $res['reply'];
                $gateway->sendText($tenant->whatsapp_instance, $from, $reply);
                MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $reply);
                BotTrace::log($this->tenantId, $trace, $from, 'routed_' . $intent, mb_substr($text, 0, 80));
                $st = is_array($convo->state) ? $convo->state : [];
                $st['lg_last_out'] = $this->norm($reply);
                $st['lg_last_out_at'] = time();
                $convo->state = $st;
                $convo->last_message_at = now();
                $convo->save();
                return;
            }
        }

        $cartBefore = $this->cartProductIds($convo);
        $stepBefore = is_array($convo->state) ? ($convo->state['step'] ?? null) : null;

        try {
            $hasPin = isset($this->incoming['lat'], $this->incoming['lng'])
                && $this->incoming['lat'] !== null && $this->incoming['lng'] !== null;
            if ($hasPin && ! $tenant->isMarketing()) {
                $reply = $brain->handleLocationPin(
                    $tenant, $convo,
                    (float) $this->incoming['lat'], (float) $this->incoming['lng'],
                    $this->incoming['loc_name'] ?? null, $this->incoming['loc_address'] ?? null
                );
            } else {
                $reply = $tenant->isMarketing()
                    ? $marketing->respond($tenant, $convo, $text)
                    : $brain->respond($tenant, $convo, $text);
            }
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'error', 'brain: ' . $e->getMessage());
            return;
        }
        $brainMs = (microtime(true) - $tBrain) * 1000;
        $convo->last_message_at = now();

        // ----- Image-search feedback loop -----
        // Label the product the customer chose after a photo search. When a candidate
        // from the photo lands in the cart — this message (single match) or a later one
        // (they reply with a number) — store image_hash + query + product as training data.
        $cartAfter = $this->cartProductIds($convo);
        $addedId = null; $addedName = null;
        foreach ($cartAfter as $pid => $nm) {
            if (! array_key_exists($pid, $cartBefore)) { $addedId = $pid; $addedName = $nm; break; }
        }

        // ---- Combo conversion: did the customer add a product we recently recommended? ----
        try {
            if ($addedId !== null && (bool) $tenant->setting('combo_recommendations', true)) {
                $shownPrev = (array) (($convo->state['combo_shown'] ?? []));
                $hit = $shownPrev[(string) $addedId] ?? null;
                if (is_array($hit) && (time() - (int) ($hit['ts'] ?? 0)) <= 3600) {
                    app(\App\Services\Bot\ComboAnalytics::class)
                        ->recordConversion($tenant->id, ((int) ($hit['src'] ?? 0)) ?: null, (int) $addedId);
                    unset($shownPrev[(string) $addedId]);
                    $stc = is_array($convo->state) ? $convo->state : [];
                    $stc['combo_shown'] = $shownPrev;
                    $convo->state = $stc;
                }
            }
        } catch (\Throwable $e) {}

        // ---- Analytics: product add + checkout-start events. ----
        try {
            if ($addedId !== null) \App\Support\ProductEvents::log((int) $tenant->id, (int) $addedId, 'add');
            $stepNow = is_array($convo->state) ? ($convo->state['step'] ?? null) : null;
            if ($stepNow === 'awaiting_confirm' && $stepBefore !== 'awaiting_confirm') {
                \App\Support\ProductEvents::log((int) $tenant->id, null, 'checkout');
            }
        } catch (\Throwable $e) {}

        $st = is_array($convo->state) ? $convo->state : [];
        if ($fromImage) {
            if ($addedId !== null && in_array((string) $addedId, $imgCandIds, true)) {
                $this->recordImageFeedback($tenant->id, $imgHash, $imgQuery, $addedId, $addedName, $imgConf, $from);
                BotTrace::log($this->tenantId, $trace, $from, 'photo_selected', $addedName);
                unset($st['img_fb']);
            } else {
                // Options shown — remember so the pick on the NEXT message is labelled.
                $st['img_fb'] = ['hash' => $imgHash, 'query' => $imgQuery, 'conf' => $imgConf, 'cands' => $imgCandIds, 'at' => time()];
            }
            $convo->state = $st;
        } elseif ($addedId !== null && is_array($st['img_fb'] ?? null)) {
            $fb = $st['img_fb'];
            if ((time() - (int) ($fb['at'] ?? 0)) <= 900 && in_array((string) $addedId, (array) ($fb['cands'] ?? []), true)) {
                $this->recordImageFeedback($tenant->id, (string) $fb['hash'], (string) $fb['query'], $addedId, $addedName, (int) ($fb['conf'] ?? 0), $from);
                BotTrace::log($this->tenantId, $trace, $from, 'photo_selected', $addedName);
                unset($st['img_fb']);
                $convo->state = $st;
            }
        }

        // Let the customer know the reply came from their photo.
        if ($fromImage && $reply !== '') {
            $reply = "📸 From your photo, here's what I found:\n\n".$reply;
        }

        if ($reply === '') {
            BotTrace::log($this->tenantId, $trace, $from, 'empty', 'bot produced no reply');
            $convo->save();
            return;
        }

        // ---- Repeat guard: never send the same message twice in a row. ----
        // If the brain has nothing new to say (e.g. it already escalated "the shop
        // will reply"), staying silent is better than repeating + re-spamming the
        // website link. After a couple of repeats, hand the chat to a human so the
        // customer isn't left hanging.
        if (! empty($state['lg_last_out']) && $this->norm($reply) === $state['lg_last_out']) {
            $st = is_array($convo->state) ? $convo->state : [];
            $rep = (int) ($st['lg_repeat'] ?? 0) + 1;
            $st['lg_repeat'] = $rep;
            if ($rep >= 2) {
                $convo->agent_active = true;                 // hand to a human
                $alerted = ! empty($st['lg_alerted']);
                $st['lg_alerted'] = true;
                $convo->state = $st;
                $convo->save();
                if (! $alerted) {
                    NotifyOwner::dispatch($this->tenantId,
                        "💬 +{$this->incoming['from']} keeps asking something the bot can't answer — auto-reply is paused for this chat. Open Chats to reply.");
                }
                BotTrace::log($this->tenantId, $trace, $from, 'paused', 'repeated reply ×' . $rep . ' — handed to human');
            } else {
                $convo->state = $st;
                $convo->save();
                BotTrace::log($this->tenantId, $trace, $from, 'skipped', 'suppressed duplicate reply');
            }
            return;
        }

        // ---- Combo add-ons at checkout: append suggestions to the confirm message. ----
        try {
            if ((bool) $tenant->setting('combo_recommendations', true)
                && (($convo->state['step'] ?? null) === 'awaiting_confirm') && $reply !== '') {
                $curCk = (string) $tenant->setting('currency', 'UGX');
                $comboCk = app(\App\Services\Bot\ComboEngine::class)
                    ->recommendForCart($tenant->id, array_map('intval', array_keys($cartAfter)), 2);
                $blockCk = $this->comboBlock($comboCk, $curCk, "🍽️ Add before you finish?");
                if ($blockCk !== '') {
                    $reply .= "\n\n" . $blockCk . "\nReply with a name to add, or *confirm* to place the order.";
                    $cartIds = array_map('intval', array_keys($cartAfter));
                    $srcCk   = $cartIds[0] ?? null;
                    $recCk   = array_map(fn ($c) => (int) $c['id'], $comboCk);
                    app(\App\Services\Bot\ComboAnalytics::class)->recordImpressions($tenant->id, $srcCk, $recCk, 'checkout');
                    $this->stashCombosShown($convo, $srcCk, $recCk);
                }
            }
        } catch (\Throwable $e) {}

        // ---- Product image response: send matching product image(s) BEFORE the text,
        // so a "Kaju Katli" / "show sweets" ask gets the photo first, then the card.
        // Never breaks the text reply; works for Evolution and Cloud via sendImage().
        try {
            $imgs = app(\App\Services\Bot\ProductImageResponder::class)
                ->imagesFor($tenant, $convo, $text);
            foreach (array_slice($imgs, 0, 5) as $im) {
                if (! empty($im['media'])) {
                    $gateway->sendImage($tenant->whatsapp_instance, $this->incoming['from'], $im['media'], (string) ($im['caption'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'img_skip', 'image send: ' . $e->getMessage());
        }

        $tSend = microtime(true);
        try {
            $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $reply);
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'error', 'send: ' . $e->getMessage());
            $convo->save();
            return;
        }
        $sendMs = (microtime(true) - $tSend) * 1000;
        MessageLog::record(
            $this->tenantId, $this->incoming['from'], $this->incoming['instance'],
            'out', 'bot', $reply
        );

        // ---- Intro catalogue: ride the bot's first substantive reply with the catalogue. ----
        // Opt-in (settings.intro_catalog.enabled), ONCE per customer, never if they already asked for
        // the catalogue (the brain sent it), and wrapped so it can never affect the main reply.
        try {
            $intro   = (array) $tenant->setting('intro_catalog', []);
            $stIntro = is_array($convo->state) ? $convo->state : [];
            $askedCatalog = (bool) preg_match('/catalog|brochure|price ?list|\bpdf\b|product list/i', $text);
            if (($intro['enabled'] ?? false)
                && empty($stIntro['intro_catalog_at'])
                && empty($stIntro['do_not_contact'])
                && (int) ($stIntro['human_until'] ?? 0) <= time()
                && ! $askedCatalog) {
                $link  = trim((string) (((array) $tenant->setting('reengage_followup', []))['link'] ?? ''));
                $files = collect((array) $tenant->setting('catalog_files', []))
                    ->map(fn ($m) => ['label' => (string) ($m['label'] ?? 'Catalogue'), 'url' => (string) ($m['url'] ?? '')])
                    ->filter(fn ($m) => $m['url'] !== '')->values()->all();
                if ($link !== '' || $files) {
                    $cmsg = trim((string) ($intro['message'] ?? '')) ?: 'Here is our catalogue 👇';
                    if ($link !== '') $cmsg .= "\n" . $link;
                    $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $cmsg);
                    MessageLog::record($this->tenantId, $from, $this->incoming['instance'], 'out', 'bot', $cmsg, null, null, ['via' => 'intro_catalog']);
                    foreach (array_slice($files, 0, 2) as $f) {
                        $gateway->sendDocument($tenant->whatsapp_instance, $this->incoming['from'], $f['url'], ($f['label'] ?: 'Catalogue') . '.pdf', $f['label']);
                    }
                    $stIntro['intro_catalog_at'] = now()->toIso8601String();
                    $convo->state = $stIntro;
                    BotTrace::log($this->tenantId, $trace, $from, 'intro_catalog', 'sent with first reply');
                }
            }
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'intro_skip', $e->getMessage());
        }

        // ---- Combo follow-up: after a product ask or an add-to-cart, suggest pairings. ----
        try {
            $focalThisTurn = (int) ($convo->state['combo_focal'] ?? 0);
            if ((bool) $tenant->setting('combo_recommendations', true)
                && (($convo->state['step'] ?? null) !== 'awaiting_confirm')) {
                $focal   = (int) ($addedId ?: $focalThisTurn);
                $context = $addedId ? 'after_add' : 'single_product';
                // Sales-manners fix (2026-07-18): suggestions ride ONLY on a committed
                // add-to-cart. A customer merely ASKING about a product gets an answer,
                // not a bazaar. (Checkout attach below is unchanged — that's honest retail.)
                if ($addedId && $focal > 0) {
                    $curFu  = (string) $tenant->setting('currency', 'UGX');
                    $comboFu = app(\App\Services\Bot\ComboEngine::class)
                        ->recommendForProduct($tenant->id, $focal, 3, array_map('intval', array_keys($cartAfter)));
                    $keyFu = $focal . ':' . implode(',', array_map(fn ($c) => $c['id'], $comboFu));
                    if ($comboFu && (($convo->state['last_combo_key'] ?? '') !== $keyFu)) {
                        $blockFu = $this->comboBlock($comboFu, $curFu, "🍽️ Often bought together:");
                        if ($blockFu !== '') {
                            $gateway->sendText($tenant->whatsapp_instance, $this->incoming['from'], $blockFu . "\nReply with a name to add.");
                            $recFu = array_map(fn ($c) => (int) $c['id'], $comboFu);
                            app(\App\Services\Bot\ComboAnalytics::class)->recordImpressions($tenant->id, $focal, $recFu, $context);
                            $this->stashCombosShown($convo, $focal, $recFu);
                            $stc = is_array($convo->state) ? $convo->state : [];
                            $stc['last_combo_key'] = $keyFu;
                            $convo->state = $stc;
                        }
                    }
                }
            }
            if ($focalThisTurn) {   // per-turn focal consumed — never leak into a later turn
                $stf = is_array($convo->state) ? $convo->state : [];
                unset($stf['combo_focal']);
                $convo->state = $stf;
            }
        } catch (\Throwable $e) {}

        // Record this auto-reply for the loop guard (re-read state: the brain may have changed it).
        $st = is_array($convo->state) ? $convo->state : [];
        $outTimes[] = $now;
        $st['lg_out_times']   = array_values(array_filter($outTimes, fn ($t) => ($now - (int) $t) <= 600));
        $st['lg_last_out']    = $this->norm($reply);
        $st['lg_last_out_at'] = $now;
        $st['lg_repeat']      = 0;
        $convo->state = $st;

        // Timing: webhook-received → reply-sent (queue wait + AI + send).
        $recv    = (float) ($this->incoming['t_recv'] ?? $tStart);
        $totalMs = (int) round((microtime(true) - $recv) * 1000);
        Log::info('bot.latency', [
            'tenant'   => $this->tenantId,
            'from'     => $this->incoming['from'],
            'mode'     => $tenant->isMarketing() ? 'marketing' : 'shop',
            'queue_ms' => (int) round(($tStart - $recv) * 1000),
            'brain_ms' => (int) round($brainMs),
            'send_ms'  => (int) round($sendMs),
            'total_ms' => $totalMs,
        ]);
        BotTrace::log($this->tenantId, $trace, $from, 'replied', mb_substr($reply, 0, 80), $totalMs);
        $convo->save();
    }

    /** Normalise text for echo comparison. */
    private function norm(string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    }

    /**
     * Is this Facebook-ad title actually our page / campaign / brand name (not a product)?
     * True when it matches any entry in the tenant's `ad_ignore_titles` setting (comma/newline
     * separated), or the tenant's own business name. Used so an ad titled e.g. "SkyTrips" is not
     * mistaken for a product the customer is asking about. Case-insensitive, punctuation-tolerant.
     */
    private function adTitleIsBrand(\App\Models\Tenant $tenant, string $head): bool
    {
        $clean = fn (string $s) => trim(preg_replace('/[^a-z0-9 ]+/', '', strtolower($s)));
        $h = $clean($head);
        if ($h === '') return false;

        $names = [];
        $raw = (string) $tenant->setting('ad_ignore_titles', '');
        foreach (preg_split('/[,\n;]+/', $raw) as $n) {
            $n = $clean((string) $n);
            if ($n !== '') $names[] = $n;
        }
        // Our own business name is always a brand name, never a product.
        $bn = $clean((string) $tenant->name);
        if ($bn !== '') $names[] = $bn;

        foreach ($names as $n) {
            // whole-title match, or the brand name appears as a word-run inside the title
            if ($h === $n || str_contains($h, $n) || str_contains($n, $h)) return true;
        }
        return false;
    }

    /**
     * Known-image shortcut: if customers have repeatedly chosen the same product for
     * this exact photo, return it so we can skip the vision call. Needs enough votes
     * and a clear winner (both configurable per tenant). Never throws.
     *
     * @return array{product_id:mixed,name:string,votes:int,total:int}|null
     */
    private function knownImageProduct(Tenant $tenant, string $hash): ?array
    {
        if ($hash === '') return null;
        $minVotes     = max(2, (int) $tenant->setting('image_shortcut_min_votes', 3));
        $minShare     = (float) $tenant->setting('image_shortcut_min_share', 0.8);
        $minCustomers = max(1, (int) $tenant->setting('image_shortcut_min_customers', 2));

        try {
            $rows = \Illuminate\Support\Facades\DB::table('image_search_feedback')
                ->where('tenant_id', $tenant->id)
                ->where('image_hash', $hash)
                ->whereNotNull('product_id')
                ->selectRaw('product_id, MAX(product_name) as name, count(*) as votes, count(distinct customer_phone) as uniq')
                ->groupBy('product_id')
                ->orderByDesc('votes')
                ->get();
        } catch (\Throwable $e) {
            return null;
        }
        if ($rows->isEmpty()) return null;

        $total = (int) $rows->sum('votes');
        $top   = $rows->first();
        if ($total >= $minVotes
            && $total > 0
            && ((int) $top->votes / $total) > $minShare
            && (int) $top->uniq >= $minCustomers) {
            return [
                'product_id' => $top->product_id,
                'name'       => (string) $top->name,
                'votes'      => (int) $top->votes,
                'total'      => $total,
            ];
        }
        return null;
    }

    /** Map of product_id => name currently in the conversation cart. */
    private function cartProductIds(Conversation $convo): array
    {
        $out = [];
        foreach ((is_array($convo->cart) ? $convo->cart : []) as $line) {
            if (is_array($line) && isset($line['product_id'])) {
                $out[(string) $line['product_id']] = (string) ($line['name'] ?? '');
            }
        }
        return $out;
    }

    /** Format a combo suggestion block (header + up to 3 priced bullet lines). */
    private function comboBlock(array $items, string $cur, string $header): string
    {
        if (! $items) return '';
        $lines = [$header];
        foreach ($items as $it) {
            $nm = (string) ($it['name'] ?? '');
            if ($nm === '') continue;
            $pr = (float) ($it['price'] ?? 0);
            $lines[] = '• ' . $nm . ($pr > 0 ? ' — ' . $cur . ' ' . number_format($pr) : '');
        }
        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /** Remember which products we just recommended (rec_id => {src,ts}) for conversion attribution. */
    private function stashCombosShown(Conversation $convo, ?int $src, array $recIds): void
    {
        $st    = is_array($convo->state) ? $convo->state : [];
        $shown = (array) ($st['combo_shown'] ?? []);
        $now   = time();
        foreach ($recIds as $rid) {
            $rid = (int) $rid;
            if ($rid > 0) $shown[(string) $rid] = ['src' => (int) $src, 'ts' => $now];
        }
        if (count($shown) > 20) $shown = array_slice($shown, -20, 20, true);
        $st['combo_shown'] = $shown;
        $convo->state = $st;
    }

    /** Store an image-search → chosen-product label (training data). Never throws. */
    private function recordImageFeedback(int $tenantId, string $hash, string $query, $productId, ?string $name, int $conf, string $customerPhone = ''): void
    {
        if ($hash === '' || $query === '') return;
        try {
            \Illuminate\Support\Facades\DB::table('image_search_feedback')->insert([
                'tenant_id'      => $tenantId,
                'customer_phone' => $customerPhone !== '' ? substr($customerPhone, 0, 32) : null,
                'image_hash'     => substr($hash, 0, 64),
                'vision_query'   => mb_substr($query, 0, 160),
                'product_id'     => $productId ?: null,
                'product_name'   => ($name !== null && $name !== '') ? mb_substr($name, 0, 200) : null,
                'confidence'     => $conf > 0 ? min(100, $conf) : null,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // feedback logging must never break message handling
        }
    }

    /**
     * Hand a message to the shared n8n smart-bot. Builds the CloudBSS→n8n contract and POSTs it
     * with the per-tenant shared secret. n8n decides what to say + whom to alert, and posts back
     * to /api/bot/reply and /api/bot/alert. The inbound is already logged, so an n8n outage never
     * loses the message — at worst the customer gets a soft ack and staff are flagged.
     */
    /** The FAQ the bot should answer from: the tenant's saved FAQ, else the manufacturer default. */
    private function brandFaq(Tenant $tenant): array
    {
        $faq = $tenant->setting('faq', null);
        if (is_array($faq) && $faq) return array_values($faq);
        if (\App\Support\Vertical::of($tenant) === \App\Support\Vertical::MANUFACTURER) {
            return \App\Support\BrandDefaults::faq();
        }
        return [];
    }

    /** Last few turns for this customer so the n8n bot has conversation memory (oldest→newest). */
    private function recentHistory(int $tenantId, string $phone, int $limit = 10): array
    {
        try {
            return \App\Models\Message::where('tenant_id', $tenantId)
                ->where('customer_phone', $phone)
                ->latest('id')->take($limit)->get()
                ->reverse()->values()
                ->map(fn ($m) => ['dir' => $m->direction, 'sender' => $m->sender, 'text' => (string) $m->body])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** alert_routing may be stored as role=>"256x, 256y" (admin KeyValue). Normalise to role=>[digits...]. */
    private function normalizeRouting(array $routing): array
    {
        $out = [];
        foreach ($routing as $role => $val) {
            $list = is_array($val) ? $val : preg_split('/[,\s]+/', (string) $val);
            $nums = array_values(array_filter(array_map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p), $list)));
            if ($nums) $out[$role] = $nums;
        }
        return $out;
    }

    private function forwardToN8n(Tenant $tenant, Conversation $convo, string $from, string $text, string $trace): void
    {
        $url    = trim((string) $tenant->setting('n8n_webhook_url', ''));
        $secret = (string) $tenant->setting('n8n_secret', '');
        if ($url === '') {
            BotTrace::log($this->tenantId, $trace, $from, 'n8n_skipped', 'no n8n_webhook_url configured');
            return;
        }

        $payload = [
            'tenant_id'     => $tenant->id,
            'tenant_slug'   => (string) $tenant->slug,
            'vertical'      => \App\Support\Vertical::of($tenant),
            'customer'      => [
                'phone' => $from,
                'name'  => (string) ($convo->customer_name ?? ''),
                'jid'   => $from . '@s.whatsapp.net',
            ],
            'message'       => [
                'text'      => $text,
                'type'      => (string) ($this->incoming['type'] ?? 'conversation'),
                'messageId' => $this->incoming['messageId'] ?? null,
            ],
            'persona'         => (string) $tenant->setting('ai_persona', ''),
            'brand_knowledge' => (string) $tenant->setting('brand_knowledge', ''),
            'faq'             => $this->brandFaq($tenant),
            'alert_routing'   => $this->normalizeRouting((array) $tenant->setting('alert_routing', [])),
            'history'         => $this->recentHistory($tenant->id, $from),
            'reply_url'     => url('/api/bot/reply'),
            'alert_url'     => url('/api/bot/alert'),
            'catalog_url'   => url('/api/tenant/' . $tenant->id . '/catalog'),
        ];

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(8)
                ->withHeaders(['X-CloudBSS-Secret' => $secret])
                ->post($url, $payload);
            if ($res->successful()) {
                BotTrace::log($this->tenantId, $trace, $from, 'n8n_dispatched', 'handed to smart bot');
                return;
            }
            BotTrace::log($this->tenantId, $trace, $from, 'n8n_error', 'n8n returned ' . $res->status());
        } catch (\Throwable $e) {
            BotTrace::log($this->tenantId, $trace, $from, 'n8n_unreachable', $e->getMessage());
        }

        // n8n failed — the inbound is already logged. Optional soft ack so the customer isn't ignored.
        if ($tenant->setting('n8n_soft_ack', false)) {
            try {
                $gw  = app(\App\Services\WhatsApp\WhatsAppManager::class)->forTenant($tenant);
                $ack = (string) $tenant->setting('n8n_soft_ack_text', 'Thanks for your message — we\'ll get right back to you.');
                $gw->sendText($tenant->whatsapp_instance, $from, $ack);
                MessageLog::record($tenant->id, $from, $tenant->whatsapp_instance, 'out', 'system', $ack);
            } catch (\Throwable $e) {
                // never break on the fallback
            }
        }
    }
}
