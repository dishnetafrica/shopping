<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\TimelineEvent;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use Illuminate\Support\Carbon;

/**
 * Chat — one WhatsApp conversation as an object workspace (thread + reply).
 *
 * Read: the last 60 messages rendered on the timeline (customer / bot / agent /
 * system, oldest→newest). Write: EVERYTHING through the proven inbox endpoints
 * via queryWrite — /papi/chats/send (reply; server-side it also flips the chat
 * to agent mode, same as the classic screen), /papi/chats/takeover (hand back
 * to bot clears the loop-guard exactly as today). No new write path exists.
 */
final class ChatCapability implements ObjectCapability
{
    private const THREAD = 60;

    public function key(): string { return 'chat'; }

    public function permission(): string { return 'menu:whatsapp'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        // An object capability without an id has nothing to show — point at the list.
        return (new WorkspacePayload)
            ->object(['type' => 'chat', 'title' => 'Chats', 'subtitle' => 'Pick a conversation from Chats'])
            ->meta(['capability' => 'chat']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $c = Conversation::where('tenant_id', $t)->find((int) $id);
        // Sales team (Internet-provider businesses): another salesperson's customer is not opened.
        if ($c && \App\Services\Sales\SalesOwnership::enabled($ctx->tenant) && ! \App\Services\Sales\SalesOwnership::canManage($ctx->user)
            && $c->owner_user_id && (int) $c->owner_user_id !== (int) $ctx->user->id) {
            $c = null;
        }
        if (! $c) {
            return (new WorkspacePayload)
                ->object(['type' => 'chat', 'title' => 'Conversation not found'])
                ->meta(['capability' => 'chat', 'error' => 'not_found']);
        }

        $phone   = (string) $c->customer_phone;
        $profile = CustomerProfile::where('tenant_id', $t)->where('phone', $phone)->first();
        $name    = trim((string) ($profile->name ?? ''));
        if ($name === '') {
            // Fallback: the latest order this phone ever placed carries their name.
            $name = trim((string) \App\Models\Order::where('tenant_id', $t)
                ->where('customer_phone', $phone)->whereNotNull('customer_name')
                ->orderByDesc('id')->value('customer_name'));
        }
        $name = $name !== '' ? $name : $phone;

        // v4.94 — a WEBSITE conversation: identify it by the contact the pre-chat form captured, not
        // by the session key. Staff opening this needed a name to greet and a number to ring; the
        // header was showing them `web:w-msmradvd-u0e7sai3cztbtsvty3` twice.
        $isWeb   = (string) $c->instance === \App\Services\Bot\WebChatPolicy::INSTANCE;
        $chanTag = 'WhatsApp';
        if ($isWeb) {
            $chanTag  = '🌐 Website chat';
            $st       = is_array($c->state) ? $c->state : [];
            $webName  = trim((string) ($st['contact_name'] ?? ''));
            $webPhone = trim((string) ($st['contact_phone'] ?? ($st['linked_phone'] ?? '')));
            if ($webName !== '')  { $name = $webName; }
            elseif ($name === $phone) { $name = 'Website visitor'; }
            $phone = $webPhone !== '' ? '+' . ltrim($webPhone, '+') : 'no number given yet';
            $src = trim((string) ($st['campaign'] ?? '')) ?: trim((string) ($st['source'] ?? ''));
            if ($src !== '') { $chanTag .= ' · ' . str_replace('-', ' ', $src); }
        }

        $canChat = $ctx->can('menu:whatsapp');

        $msgs = Message::where('tenant_id', $t)
            ->where('customer_phone', $phone)
            ->orderByDesc('id')->limit(self::THREAD)->get()->reverse()->values();

        $timeline = [];
        foreach ($msgs as $m) {
            $in    = $m->direction === 'in';
            $actor = $in ? $name : (($m->sender === 'bot') ? 'Bot' : (($m->sender === 'system') ? 'System' : 'Agent'));
            $timeline[] = new TimelineEvent(
                at: Carbon::parse($m->created_at)->toDateTimeString(),
                type: $in ? 'inbound' : 'outbound',
                title: ($in ? '👤 ' : ($m->sender === 'bot' ? '🤖 ' : '🧑‍💼 ')).$actor,
                detail: (string) $m->body,
            );
        }

        // Auto-translate backfill (2026-07-18): opening a chat translates up to 4
        // still-untranslated Arabic messages (newest first) — history heals itself as
        // staff work. Cached on the rows, so each message costs one AI call EVER.
        try {
            if ((bool) $ctx->tenant->setting('chat_auto_translate', true)) {
                $translator = app(\App\Services\Chat\ChatTranslator::class);
                if ($translator->enabled()) {
                    $todo = 4;
                    foreach ($msgs->reverse() as $mm2) {
                        if ($todo <= 0) break;
                        if ($mm2->direction !== 'in') continue;
                        if (! empty(((array) ($mm2->meta ?? []))['tr_en'])) continue;
                        if (! \App\Services\Chat\ChatTranslator::hasArabic((string) $mm2->body)) continue;
                        $translator->translateAndCache($mm2);
                        $todo--;
                    }
                }
            }
        } catch (\Throwable $e) {
            // never block the thread view on translation
        }

        // WhatsApp-Web mode: a parallel rich transcript for the split-pane renderer —
        // same rows, plus on-demand media (image/audio/file) resolved to a src.
        $rich = [];
        foreach ($msgs as $m) {
            $media = null;
            $mm = (array) (($m->meta['media'] ?? null) ?: []);
            if ($mm) {
                $kind = (string) ($mm['kind'] ?? 'file');
                if ($kind === 'location' && isset($mm['lat'], $mm['lng'])) {
                    $media = array_filter([
                        'kind' => 'location',
                        'name' => $mm['name'] ?? null,
                        'address' => $mm['address'] ?? null,
                        'maps' => 'https://maps.google.com/?q='.((float) $mm['lat']).','.((float) $mm['lng']),
                    ]);
                } else {
                    // media_key can be an array (Evolution message key) — json-pack it for the query.
                    $keyRaw = $mm['key'] ?? null;
                    $keyStr = is_array($keyRaw) ? json_encode($keyRaw) : (string) ($keyRaw ?? '');
                    $src = isset($mm['url']) && $mm['url']
                        ? (string) $mm['url']
                        : ($keyStr !== ''
                            ? '/papi/chats/media?key='.rawurlencode($keyStr)
                                .'&mime='.rawurlencode((string) ($mm['mime'] ?? ''))
                            : null);
                    if ($src) {
                        $media = array_filter([
                            'kind' => $kind, 'src' => $src,
                            'name' => $mm['name'] ?? null,
                        ]);
                    }
                }
            }
            $rich[] = [
                'id' => (int) $m->id,
                'dir' => $m->direction === 'in' ? 'in' : 'out',
                'sender' => (string) $m->sender,
                'at' => Carbon::parse($m->created_at)->format('d M H:i'),
                'body' => (string) $m->body,
                'tr' => (string) (($m->meta['tr_en'] ?? '') ?: '') ?: null,
                'media' => $media,
            ];
        }

        $actions = [];
        if ($canChat) {
            $actions[] = ['key' => 'reply', 'label' => 'Reply', 'queryWrite' => true, 'method' => 'POST',
                'endpoint' => '/papi/chats/send', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['body' => 'text'],
                'labels' => ['body' => 'Message to '.$name],
                'body'   => ['phone' => $phone]];
            $actions[] = ['key' => 'send_image', 'label' => 'Send photo', 'submitLabel' => 'Send', 'method' => 'POST',
                'endpoint' => '/papi/chats/send-media', 'actor' => 'human',
                'schema' => ['image' => 'image', 'caption' => 'text'],
                'labels' => ['image' => 'Photo', 'caption' => 'Caption (optional)'],
                'body'   => ['phone' => $phone]];
            $actions[] = ['key' => 'send_file', 'label' => 'Send document', 'submitLabel' => 'Send', 'method' => 'POST',
                'endpoint' => '/papi/chats/send-document', 'actor' => 'human',
                'schema' => ['file' => 'file:.pdf,.doc,.docx,.xls,.xlsx,.csv', 'filename' => 'text', 'caption' => 'text'],
                'labels' => ['file' => 'Document (PDF, Word, Excel…)', 'filename' => 'File name shown to the customer', 'caption' => 'Caption (optional)'],
                'body'   => ['phone' => $phone]];
            $actions[] = ['key' => 'send_catalogue', 'label' => 'Send photo catalogue (PDF)', 'method' => 'POST',
                'endpoint' => '/papi/chats/send-catalogue', 'actor' => 'human', 'submitLabel' => 'Send',
                'schema' => ['caption' => 'text'],
                'labels' => ['caption' => 'Caption (optional)'],
                'body'   => ['phone' => $phone]];
            $actions[] = ['key' => 'send_location', 'label' => 'Send a location', 'submitLabel' => 'Send', 'method' => 'POST',
                'endpoint' => '/papi/chats/send-location', 'actor' => 'human',
                'schema' => ['lat' => 'number', 'lng' => 'number', 'name' => 'text', 'address' => 'text'],
                'labels' => ['lat' => 'Latitude', 'lng' => 'Longitude', 'name' => 'Place name', 'address' => 'Address (optional)'],
                'prefill' => ['name' => (string) ($ctx->tenant->name ?? 'Our shop')],
                'body'   => ['phone' => $phone]];
            $actions[] = $c->agent_active
                ? ['key' => 'to_bot', 'label' => 'Hand back to bot', 'queryWrite' => true, 'method' => 'POST',
                    'endpoint' => '/papi/chats/takeover', 'actor' => 'human',
                    'body' => ['phone' => $phone, 'active' => 0],
                    'confirm' => 'Let the bot answer '.$name.' again?']
                : ['key' => 'takeover', 'label' => 'Take over', 'queryWrite' => true, 'method' => 'POST',
                    'endpoint' => '/papi/chats/takeover', 'actor' => 'human', 'tone' => 'warn',
                    'body' => ['phone' => $phone, 'active' => 1],
                    'confirm' => 'Pause the bot and handle '.$name.' yourself?'];

            // AI Sales Coach (Internet providers only): suggests the next reply into the composer, where the
            // salesperson edits it and sends it through the normal reply above. The coach never sends.
            if (\App\Services\Sales\SalesOwnership::enabled($ctx->tenant)) {
                $actions[] = ['key' => 'coach', 'coach' => true, 'label' => 'Suggest a reply', 'method' => 'POST',
                    'endpoint' => '/api/v1/workspace/chat/'.$c->id.'/coach', 'actor' => 'human'];
            }

            // Travel Vertical (M1): TRAVEL TENANTS ONLY. A takeover records its reason on the trip, and
            // the chat links to the customer's open trip. Other business types see no change.
            if (\App\Services\Travel\Enquiry\EnquiryService::isTravel($ctx->tenant)) {
                $last = count($actions) - 1;
                if (($actions[$last]['key'] ?? '') === 'takeover') {
                    unset($actions[$last]['confirm']);
                    $actions[$last]['submitLabel'] = 'Take over';
                    $actions[$last]['schema'] = ['reason' => 'enum:Complaint,Payment issue,Unusual request,Group booking,Change or refund,Customer asked for a person,Other'];
                    $actions[$last]['labels'] = ['reason' => 'Why are you taking over?'];
                }
                $trip = app(\App\Services\Travel\Enquiry\EnquiryService::class)->current($ctx->tenant, $phone, str_starts_with($phone, 'web:') ? 'web' : 'whatsapp');
                if ($trip) {
                    $actions[] = ['key' => 'open_trip', 'label' => 'Open trip', 'method' => 'GET',
                        'endpoint' => '/api/v1/workspace/trip/'.$trip->id, 'actor' => 'human'];
                }
            }
        }

        $related = [];
        if ($profile) {
            $related[] = ['type' => 'customer', 'id' => (string) $profile->id, 'title' => $name,
                'link' => '/api/v1/workspace/customer/'.$profile->id, 'note' => 'Customer 360'];
        }

        $lastAt = $c->last_message_at ? Carbon::parse($c->last_message_at)->diffForHumans() : '—';

        return (new WorkspacePayload)
            ->object([
                'type' => 'chat', 'id' => (string) $c->id, 'title' => $name,
                'subtitle' => $chanTag.' · '.$phone.' · '.($c->agent_active ? 'Agent mode (bot paused)' : 'Bot answering').' · last activity '.$lastAt,
            ])
            ->financials([
                ['key' => 'unread', 'label' => 'Unread', 'value' => (string) (int) $c->unread, 'tone' => ((int) $c->unread) > 0 ? 'danger' : 'ok'],
                ['key' => 'mode', 'label' => 'Mode', 'value' => $c->agent_active ? 'Agent' : 'Bot', 'tone' => $c->agent_active ? 'warn' : 'ok'],
                ['key' => 'msgs', 'label' => 'Messages shown', 'value' => (string) count($timeline), 'foot' => 'most recent '.self::THREAD],
            ])
            ->timeline($timeline)
            ->actions($actions)
            ->related($related)
            ->meta(['capability' => 'chat', 'ttl' => 10, 'thread' => true, 'msgs' => $rich, 'permissions' => ['menu:whatsapp']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action !== 'coach') {
            return ['ok' => false, 'error' => 'unknown_action'];
        }
        if (! \App\Services\Sales\SalesOwnership::enabled($ctx->tenant)) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        // Same visibility as opening the chat: a salesperson never gets coaching on a colleague's customer.
        $c = Conversation::where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if ($c && ! \App\Services\Sales\SalesOwnership::canManage($ctx->user)
            && $c->owner_user_id && (int) $c->owner_user_id !== (int) $ctx->user->id) {
            $c = null;
        }
        if (! $c) {
            return ['ok' => false, 'error' => 'not_found'];
        }

        return app(\App\Services\Isp\SalesCoach::class)->suggest($ctx->tenant, $c, $ctx->user);
    }
}
