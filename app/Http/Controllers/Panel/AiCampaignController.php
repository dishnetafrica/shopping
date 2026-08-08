<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\AiCampaignDraft;
use App\Models\Campaign;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Http\Request;

/**
 * AI CAMPAIGN GENERATOR (v4.5) — "target customers with what they are looking for."
 *
 * Flow: pick an AUDIENCE (stage / memory-contains / recency) → the AI writes ONE
 * personalised message PER CUSTOMER from their relationship memory → every draft sits in a
 * REVIEW QUEUE (approve / edit / skip; nothing sends without a human) → aic:send-tick sends
 * approved drafts PACED (2/min, daytime only, idempotent via campaign_messages claims — the
 * same protections the existing SendCampaign job documents: fast broadcasting gets WhatsApp
 * numbers banned).
 *
 * Suppression at generation: no phone · already in an active AI campaign · customer with a
 * CONFIRMED but unconverted quotation (never market mid-deal).
 * Owner-only, like settings.
 */
class AiCampaignController extends Controller
{
    private function owner(Request $request)
    {
        $u = $request->user();
        if (! $u || ! $u->tenant_id || ! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return null;
        }

        return $u;
    }

    public function page(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/ai-campaign.html');
        if (! is_file($path)) {
            abort(500, 'AI campaign asset missing.');
        }
        $html = str_replace('{{TENANT}}', e((string) ($u->tenant->name ?? 'CloudBSS')), file_get_contents($path));

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Shared audience query. Filters: stage, contains (memory text), active_days. */
    private function audienceQuery($t, array $f)
    {
        $q = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereNotNull('phone')->where('phone', '!=', '');
        $stage = strtoupper(trim((string) ($f['stage'] ?? '')));
        if ($stage !== '' && $stage !== 'ANY') {
            $q->whereRaw('UPPER(COALESCE(stage, \'\')) = ?', [$stage]);
        }
        // v4.60 — applied AFTER the stage filter and unconditionally, so that picking DISCOVERED in
        // the dropdown produces an empty audience rather than a blast. A discovered business enters
        // with marketing_opt_in false and has agreed to nothing; the choice is not the owner's to
        // make here, it is the business's, and nobody has asked them yet.
        \App\Services\Crm\BusinessStage::scope($q);
        $contains = trim((string) ($f['contains'] ?? ''));
        if ($contains !== '') {
            $like = '%' . mb_strtolower($contains) . '%';
            $q->where(function ($w) use ($like) {
                $w->whereRaw('LOWER(COALESCE(ai_summary, \'\')) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(CAST(facts AS TEXT), \'\')) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(CAST(prefs AS TEXT), \'\')) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(COALESCE(notes, \'\')) LIKE ?', [$like]);
            });
        }
        $days = (int) ($f['active_days'] ?? 0);
        if ($days > 0) {
            $q->where('last_whatsapp_at', '>=', now()->subDays($days));
        }

        return $q->orderByDesc('last_whatsapp_at');
    }

    /** Phones to NEVER market to right now: confirmed-but-unconverted quotations (mid-deal). */
    private function suppressedPhones($t): array
    {
        $out = [];
        try {
            foreach (Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->whereNull('order_id')->whereNotNull('meta')
                ->orderByDesc('id')->limit(400)->get(['customer_phone', 'meta']) as $q) {
                $meta = is_array($q->meta) ? $q->meta : [];
                if (! empty($meta['confirmed']) && $q->customer_phone) {
                    $out[preg_replace('/[^0-9]/', '', (string) $q->customer_phone)] = true;
                }
            }
        } catch (\Throwable $e) { /* suppression is best-effort protection */ }

        return $out;
    }

    public function audience(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $t = $u->tenant;
        $q = $this->audienceQuery($t, $request->query());
        $count = (clone $q)->count();
        $sample = $q->limit(8)->get(['name', 'phone', 'stage'])
            ->map(fn ($c) => ['name' => (string) ($c->name ?: $c->phone), 'stage' => (string) ($c->stage ?? '')]);

        // v4.32 — show how many of THIS audience the do-not-message list removes, so the
        // number on screen is the number that will actually be contacted.
        $ex = \App\Services\Marketing\CampaignExclusions::for($t);
        $excluded = 0;
        if (! $ex->isEmpty()) {
            foreach ((clone $q)->pluck('phone') as $p) {
                if ($ex->blocks((string) $p)) {
                    $excluded++;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'count' => max(0, $count - $excluded),
            'total' => $count,
            'excluded' => $excluded,
            'sample' => $sample,
            'suppressed' => count($this->suppressedPhones($t)),
        ]);
    }

    public function generate(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $t = $u->tenant;
        $data = $request->validate([
            'title'       => 'required|string|max:120',
            'brief'       => 'required|string|max:600',
            'offer'       => 'nullable|string|max:300',
            'stage'       => 'nullable|string|max:40',
            'contains'    => 'nullable|string|max:120',
            'active_days' => 'nullable|integer|min:0',
            'limit'       => 'nullable|integer|min:1|max:40',
            'campaign_id' => 'nullable|integer',   // continue filling an existing draft campaign
        ]);
        if (! (config('openai.api_key') ?: env('OPENAI_API_KEY')) && trim((string) $t->setting('openai_api_key', '')) === '') {
            return response()->json(['ok' => false, 'error' => 'AI is not configured on this server.'], 422);
        }

        // The campaign shell row (existing campaigns table → visible with the rest of marketing).
        $c = null;
        if (! empty($data['campaign_id'])) {
            $c = Campaign::withoutGlobalScopes()->where('tenant_id', $t->id)->where('type', 'ai')->find((int) $data['campaign_id']);
        }
        if (! $c) {
            $c = Campaign::create([
                'tenant_id' => $t->id,
                'name'      => (string) $data['title'],
                'type'      => 'ai',
                'audience'  => 'ai',
                'message'   => trim((string) $data['brief'] . (! empty($data['offer']) ? "\nOFFER: " . $data['offer'] : '')),
                'status'    => 'draft',
                'stats'     => [],
            ]);
        }

        $suppressed = $this->suppressedPhones($t);
        $excluded = \App\Services\Marketing\CampaignExclusions::for($t);   // v4.32 do-not-message list
        // Phones already sitting in ANY active AI campaign (don't double-target across campaigns).
        $busy = AiCampaignDraft::where('tenant_id', $t->id)
            ->whereIn('status', ['draft', 'approved'])
            ->pluck('phone')->flip()->all();

        $limit = (int) ($data['limit'] ?? 20);
        $made = 0; $skipped = 0; $failed = 0;
        @set_time_limit(300);

        $tenantKey = (string) $t->setting('openai_api_key', '');
        $client = $tenantKey !== '' ? \OpenAI::client($tenantKey) : \OpenAI\Laravel\Facades\OpenAI::getFacadeRoot();
        $model = (string) ($t->setting('ai_model', '') ?: env('OPENAI_MODEL', 'gpt-4o-mini'));

        foreach ($this->audienceQuery($t, $data)->limit($limit * 3)->get() as $p) {
            if ($made >= $limit) {
                break;
            }
            $phone = preg_replace('/[^0-9]/', '', (string) $p->phone);
            // v4.32 — the shop's do-not-message list. Checked here so no AI message is even
            // WRITTEN for an excluded number (each draft costs a model call), and again at
            // send time in AiCampaignSendCommand for anyone added to the list afterwards.
            if ($phone === '' || isset($suppressed[$phone]) || isset($busy[$phone]) || $excluded->blocks($phone)) {
                $skipped++;
                continue;
            }
            if (AiCampaignDraft::where('campaign_id', $c->id)->where('phone', $phone)->exists()) {
                $skipped++;
                continue;
            }

            $profile = '';
            try { $profile = \App\Services\Relationship\ProfileContext::block($t, $phone); } catch (\Throwable $e) {}
            $sys = 'You write ONE short WhatsApp marketing message (2-4 sentences, warm, plain trade English'
                . (trim((string) $p->lang) !== '' ? ', or in ' . $p->lang . ' if that is their language' : '')
                . ') from ' . ($t->name ?? 'our shop') . ' to ONE specific customer. Personalise it around what THIS '
                . 'customer is looking for or usually buys — never generic blast copy. Never invent prices, discounts '
                . 'or stock; if an OFFER is given below, use its exact wording. Address them by name if known. '
                . 'No "Dear", no signatures, no placeholders. Output ONLY the message text.'
                . $profile;
            $userMsg = 'CAMPAIGN: ' . $data['brief']
                . (! empty($data['offer']) ? "\nOFFER (use exactly): " . $data['offer'] : '')
                . "\nCustomer name: " . ((string) ($p->name ?: 'unknown'));

            try {
                $res = $client->chat()->create([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $sys],
                        ['role' => 'user', 'content' => $userMsg],
                    ],
                    'max_tokens' => 220,
                    'temperature' => 0.75,
                ]);
                $body = trim((string) ($res->choices[0]->message->content ?? ''));
            } catch (\Throwable $e) {
                $body = '';
                \Log::warning('aic-generate draft failed for ' . $phone . ': ' . $e->getMessage());
            }

            AiCampaignDraft::create([
                'tenant_id'   => $t->id,
                'campaign_id' => $c->id,
                'phone'       => $phone,
                'name'        => (string) ($p->name ?? ''),
                'body'        => $body !== '' ? $body : null,
                'status'      => $body !== '' ? 'draft' : 'failed',
                'error'       => $body !== '' ? null : 'AI draft failed',
            ]);
            $body !== '' ? $made++ : $failed++;
        }

        return response()->json(['ok' => true, 'campaign_id' => $c->id, 'made' => $made, 'skipped' => $skipped, 'failed' => $failed]);
    }

    public function list(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $t = $u->tenant;
        $cid = (int) $request->query('campaign_id', 0);
        $campaigns = Campaign::withoutGlobalScopes()->where('tenant_id', $t->id)->where('type', 'ai')
            ->orderByDesc('id')->limit(10)->get(['id', 'name', 'status', 'created_at'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => (string) $c->name, 'status' => (string) $c->status,
                              'date' => optional($c->created_at)->format('d M H:i')]);
        if (! $cid && count($campaigns)) {
            $cid = $campaigns[0]['id'];
        }
        $drafts = [];
        $counts = ['draft' => 0, 'approved' => 0, 'skipped' => 0, 'sent' => 0, 'failed' => 0];
        if ($cid) {
            foreach (AiCampaignDraft::where('tenant_id', $t->id)->where('campaign_id', $cid)
                ->orderBy('id')->limit(200)->get() as $d) {
                $counts[$d->status] = ($counts[$d->status] ?? 0) + 1;
                $drafts[] = ['id' => $d->id, 'phone' => (string) $d->phone, 'name' => (string) ($d->name ?? ''),
                             'body' => (string) ($d->body ?? ''), 'status' => (string) $d->status,
                             'sent_at' => optional($d->sent_at)->format('H:i')];
            }
        }
        $camp = $cid ? Campaign::withoutGlobalScopes()->where('tenant_id', $t->id)->find($cid) : null;

        return response()->json(['ok' => true, 'campaigns' => $campaigns, 'campaign_id' => $cid,
            'campaign_status' => (string) ($camp->status ?? ''), 'counts' => $counts, 'drafts' => $drafts]);
    }

    public function set(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $data = $request->validate([
            'id'     => 'required|integer',
            'status' => 'nullable|string|in:draft,approved,skipped',
            'body'   => 'nullable|string|max:1200',
        ]);
        $d = AiCampaignDraft::where('tenant_id', $u->tenant_id)->find((int) $data['id']);
        if (! $d || in_array($d->status, ['sent'], true)) {
            return response()->json(['ok' => false, 'error' => 'Draft not editable.'], 422);
        }
        if (array_key_exists('body', $data) && $data['body'] !== null) {
            $d->body = trim((string) $data['body']);
        }
        if (! empty($data['status'])) {
            if ($data['status'] === 'approved' && trim((string) $d->body) === '') {
                return response()->json(['ok' => false, 'error' => 'Cannot approve an empty message.'], 422);
            }
            $d->status = $data['status'];
        }
        $d->save();

        return response()->json(['ok' => true, 'id' => $d->id, 'status' => $d->status]);
    }

    public function approveAll(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $cid = (int) $request->input('campaign_id');
        $n = AiCampaignDraft::where('tenant_id', $u->tenant_id)->where('campaign_id', $cid)
            ->where('status', 'draft')->whereNotNull('body')->update(['status' => 'approved']);

        return response()->json(['ok' => true, 'approved' => $n]);
    }

    public function start(Request $request)
    {
        $u = $this->owner($request);
        if (! $u) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $t = $u->tenant;
        $cid = (int) $request->input('campaign_id');
        $c = Campaign::withoutGlobalScopes()->where('tenant_id', $t->id)->where('type', 'ai')->find($cid);
        if (! $c) {
            return response()->json(['ok' => false, 'error' => 'Campaign not found.'], 404);
        }
        $approved = AiCampaignDraft::where('tenant_id', $t->id)->where('campaign_id', $cid)->where('status', 'approved')->count();
        if ($approved < 1) {
            return response()->json(['ok' => false, 'error' => 'Approve at least one draft first.'], 422);
        }
        $stats = is_array($c->stats) ? $c->stats : [];
        $stats['ai_approved'] = $approved;
        $stats['started_at'] = now()->toIso8601String();
        $c->update(['status' => 'sending', 'stats' => $stats]);

        return response()->json(['ok' => true, 'sending' => $approved,
            'note' => 'Sending is PACED: about 2 messages a minute, daytime only (08:00–20:00). ' . $approved . ' messages will take roughly ' . max(1, (int) ceil($approved / 2)) . ' minutes.']);
    }
}
