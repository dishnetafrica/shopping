<?php

namespace App\Http\Controllers\Comms;

use App\Http\Controllers\Controller;
use App\Models\CommEvent;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Provider event ingest.
 *
 *   POST /papi/comms/hook/{driver}?t={tenantId}&s={secret}
 *
 * papi/* is already CSRF-exempt (bootstrap/app.php). Auth = the per-tenant
 * webhook secret (settings comms_email.webhook_secret, shown in Email Center →
 * Settings). Parsing is deliberately TOLERANT: whatever we can't map still
 * lands in comm_events with the raw payload, so nothing is ever lost.
 *
 * Message matching order: our token (custom args / metadata / tags) →
 * provider_message_id → newest message to that recipient.
 */
class EmailWebhookController extends Controller
{
    public function ingest(Request $request, string $driver)
    {
        $tenantId = (int) $request->query('t');
        $secret   = (string) $request->query('s');
        $tenant   = $tenantId ? Tenant::find($tenantId) : null;
        if (! $tenant) return response()->json(['ok' => false], 404);

        $expected = (string) $tenant->setting('comms_email.webhook_secret', '');
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->json()->all();
        if (! $payload) $payload = $request->all();

        // SendGrid POSTs an ARRAY of events; everyone else posts one object.
        $events = (array_is_list($payload) && $payload !== []) ? $payload : [$payload];

        foreach ($events as $ev) {
            if (! is_array($ev)) continue;
            $this->one($tenant->id, $driver, $ev);
        }

        return response()->json(['ok' => true]);
    }

    protected function one(int $tenantId, string $driver, array $ev): void
    {
        [$kind, $email, $token, $pmid] = $this->normalise($driver, $ev);

        $m = null;
        if ($token) {
            $m = CommMessage::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)->where('token', $token)->first();
        }
        if (! $m && $pmid) {
            $m = CommMessage::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)->where('provider_message_id', $pmid)->first();
        }
        if (! $m && $email) {
            $m = CommMessage::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)->where('to_email', mb_strtolower($email))
                ->orderByDesc('id')->first();
        }

        CommEvent::create([
            'tenant_id'   => $tenantId,
            'message_id'  => $m?->id,
            'event'       => $kind ?: 'webhook',
            'provider'    => $driver,
            'payload'     => $ev,
            'occurred_at' => now(),
        ]);

        if (! $m) return;

        switch ($kind) {
            case 'delivered':
                if (! $m->delivered_at) $m->delivered_at = now();
                break;
            case 'opened':
                $m->opens = $m->opens + 1;
                if (! $m->opened_at) $m->opened_at = now();
                break;
            case 'clicked':
                $m->clicks = $m->clicks + 1;
                break;
            case 'bounced':
                if (! $m->bounced_at) $m->bounced_at = now();
                CommSuppression::add($tenantId, $m->to_email, 'bounced', $driver);
                break;
            case 'complained':
                if (! $m->complained_at) $m->complained_at = now();
                CommSuppression::add($tenantId, $m->to_email, 'complained', $driver);
                break;
            case 'unsubscribed':
                if (! $m->unsubscribed_at) $m->unsubscribed_at = now();
                CommSuppression::add($tenantId, $m->to_email, 'unsubscribed', $driver);
                break;
        }
        $m->save();
    }

    /** @return array{0:?string,1:?string,2:?string,3:?string} [kind, email, ourToken, providerMsgId] */
    protected function normalise(string $driver, array $ev): array
    {
        $kind = null; $email = null; $token = null; $pmid = null;

        if ($driver === 'sendgrid') {
            $email = $ev['email'] ?? null;
            $token = $ev['comm_token'] ?? ($ev['custom_args']['comm_token'] ?? null);
            $pmid  = $ev['sg_message_id'] ?? null;
            $kind  = $this->mapKind($ev['event'] ?? '');
        } elseif ($driver === 'mailgun') {
            $d     = $ev['event-data'] ?? $ev;
            $email = $d['recipient'] ?? null;
            $token = $d['user-variables']['comm-token'] ?? null;
            $pmid  = $d['message']['headers']['message-id'] ?? null;
            $kind  = $this->mapKind($d['event'] ?? '');
        } elseif ($driver === 'postmark') {
            $email = $ev['Recipient'] ?? $ev['Email'] ?? null;
            $token = $ev['Metadata']['comm_token'] ?? null;
            $pmid  = $ev['MessageID'] ?? null;
            $kind  = $this->mapKind($ev['RecordType'] ?? '');
        } elseif ($driver === 'brevo') {
            $email = $ev['email'] ?? null;
            $pmid  = isset($ev['message-id']) ? (string) $ev['message-id'] : null;
            foreach ((array) ($ev['tags'] ?? ($ev['tag'] ?? [])) as $tg) {
                if (is_string($tg) && str_starts_with($tg, 'comm-')) $token = substr($tg, 5);
            }
            $kind = $this->mapKind($ev['event'] ?? '');
        }

        return [$kind, $email, $token, $pmid];
    }

    protected function mapKind(string $raw): ?string
    {
        $raw = strtolower($raw);
        return match (true) {
            in_array($raw, ['delivered', 'delivery'])                                        => 'delivered',
            in_array($raw, ['open', 'opened', 'unique_opened'])                              => 'opened',
            in_array($raw, ['click', 'clicked'])                                             => 'clicked',
            in_array($raw, ['bounce', 'bounced', 'failed', 'hard_bounce', 'dropped',
                            'permanent_fail'])                                               => 'bounced',
            in_array($raw, ['complained', 'spam', 'spamreport', 'spam_complaint',
                            'spamcomplaint'])                                                => 'complained',
            in_array($raw, ['unsubscribe', 'unsubscribed', 'subscriptionchange'])            => 'unsubscribed',
            default                                                                          => $raw !== '' ? $raw : null,
        };
    }
}
