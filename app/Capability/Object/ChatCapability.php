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

        $actions = [];
        if ($canChat) {
            $actions[] = ['key' => 'reply', 'label' => 'Reply', 'queryWrite' => true, 'method' => 'POST',
                'endpoint' => '/papi/chats/send', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['body' => 'text'],
                'labels' => ['body' => 'Message to '.$name],
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
                'subtitle' => $phone.' · '.($c->agent_active ? 'Agent mode (bot paused)' : 'Bot answering').' · last activity '.$lastAt,
            ])
            ->financials([
                ['key' => 'unread', 'label' => 'Unread', 'value' => (string) (int) $c->unread, 'tone' => ((int) $c->unread) > 0 ? 'danger' : 'ok'],
                ['key' => 'mode', 'label' => 'Mode', 'value' => $c->agent_active ? 'Agent' : 'Bot', 'tone' => $c->agent_active ? 'warn' : 'ok'],
                ['key' => 'msgs', 'label' => 'Messages shown', 'value' => (string) count($timeline), 'foot' => 'most recent '.self::THREAD],
            ])
            ->timeline($timeline)
            ->actions($actions)
            ->related($related)
            ->meta(['capability' => 'chat', 'ttl' => 10, 'thread' => true, 'permissions' => ['menu:whatsapp']]);
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
