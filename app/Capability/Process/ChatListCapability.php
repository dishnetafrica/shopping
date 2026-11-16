<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Chats — the v3 face of the classic /panel/chats WhatsApp inbox (Migration plan G1,
 * the gap that dragged everyone back to the classic panel daily).
 *
 * Reads Conversation/Message directly (allowed for a Process capability); every WRITE
 * goes through the PROVEN inbox endpoints (/papi/chats/send, /papi/chats/takeover,
 * /papi/chats/bot-mode, /papi/chats/sync) via queryWrite — zero duplicated logic, the
 * bot/loop-guard/transcript behaviour stays byte-identical to the classic screen.
 */
final class ChatListCapability implements ProcessCapability
{
    private const MAX_ROWS = 200;

    public function key(): string { return 'chats'; }

    public function permission(): string { return 'menu:whatsapp'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        $canChat = $ctx->can('menu:whatsapp');

        // Sales team (Internet-provider businesses): a salesperson sees their own chats and unassigned ones.
        $convos = \App\Services\Sales\SalesOwnership::scopeVisible(Conversation::where('tenant_id', $t), $ctx)
            ->orderByDesc('last_message_at')
            ->limit(self::MAX_ROWS)
            ->get();

        // Contact names + last message previews in two bulk queries (no N+1).
        $phones = $convos->pluck('customer_phone')->filter()->unique()->values();
        $names = CustomerProfile::where('tenant_id', $t)
            ->whereIn('phone', $phones)->pluck('name', 'phone');
        // Most contacts have no profile yet — but if they ever ORDERED, we know their name.
        // Ordered ASC so pluck keeps the LATEST name per phone.
        $orderNames = Order::where('tenant_id', $t)->whereIn('customer_phone', $phones)
            ->whereNotNull('customer_name')->where('customer_name', '!=', '')
            ->orderBy('id')->pluck('customer_name', 'customer_phone');
        $lastByPhone = Message::where('tenant_id', $t)
            ->whereIn('customer_phone', $phones)
            ->orderBy('id')
            ->get(['customer_phone', 'direction', 'sender', 'body', 'created_at'])
            ->keyBy('customer_phone');                    // ordered ASC → keyBy keeps the LAST row

        $rows = [];
        foreach ($convos as $c) {
            $phone  = (string) $c->customer_phone;
            $name   = trim((string) ($names[$phone] ?? '')) ?: (trim((string) ($orderNames[$phone] ?? '')) ?: $phone);

            // v4.94 — WEBSITE conversations. Their customer_phone is `web:<session>`, which is what the
            // inbox has been showing as the customer's name: unreadable, unsearchable, and impossible to
            // ring back. The pre-chat form already captured a real name and WhatsApp number and parked
            // them on the conversation (v4.85) — nothing read them here. Now they are the identity, the
            // session key drops to a badge, and staff can tell the two channels apart at a glance.
            $isWeb = (string) $c->instance === \App\Services\Bot\WebChatPolicy::INSTANCE;
            if ($isWeb) {
                $st       = is_array($c->state) ? $c->state : [];
                $webName  = trim((string) ($st['contact_name'] ?? ''));
                $webPhone = trim((string) ($st['contact_phone'] ?? ($st['linked_phone'] ?? '')));
                if ($webName !== '')  { $name  = $webName; }
                if ($webPhone !== '') { $phone = '+' . ltrim($webPhone, '+'); }
                elseif ($name === (string) $c->customer_phone) { $name = 'Website visitor'; $phone = 'not given yet'; }
                else { $phone = 'not given yet'; }
            }
            $unread = (int) $c->unread;
            $last   = $lastByPhone[$phone] ?? null;
            $lastAt = $c->last_message_at ? Carbon::parse($c->last_message_at) : null;

            $preview = $last ? mb_substr(trim((string) $last->body), 0, 80) : '—';
            $who     = $last ? ($last->direction === 'in' ? '👤' : ($last->sender === 'bot' ? '🤖' : '🧑‍💼')) : '';

            // Awaiting reply = customer wrote last and it's unread.
            $awaiting = $last && $last->direction === 'in' && $unread > 0;

            $rows[] = [
                'contact' => ['value' => $name, 'sub' => ($isWeb ? '🌐 Website · ' : '') . $phone, 'avatar' => true],
                'channel' => $isWeb
                    ? ['value' => 'Web', 'badge' => 'info', 'sort' => 'web']
                    : ['value' => 'WhatsApp', 'badge' => 'default', 'sort' => 'whatsapp'],
                'last'    => ['value' => trim($who.' '.$preview)],
                'unread'  => $unread > 0
                    ? ['value' => (string) $unread, 'badge' => 'danger', 'sort' => $unread]
                    : ['value' => '', 'sort' => 0],
                'mode'    => $c->agent_active
                    ? ['value' => 'Agent', 'badge' => 'warn', 'sort' => 'agent']
                    : ['value' => 'Bot', 'badge' => 'ok', 'sort' => 'bot'],
                'when'    => $lastAt
                    ? ['value' => $lastAt->diffForHumans(short: true), 'sort' => $lastAt->timestamp]
                    : ['value' => '—', 'sort' => 0],
                'link'    => '/api/v1/workspace/chat/'.$c->id,
                // hidden filter field
                'state'   => $awaiting ? 'awaiting' : ($unread > 0 ? 'unread' : 'read'),
            ];
        }

        $base = fn () => Conversation::where('tenant_id', $t);
        $stats = [
            'unread'   => (clone $base())->where('unread', '>', 0)->count(),
            'agent'    => (clone $base())->where('agent_active', true)->count(),
            'today'    => (clone $base())->where('last_message_at', '>=', now()->startOfDay())->count(),
            'inbound'  => (clone $base())->where('last_inbound_at', '>=', now()->startOfDay())->count(),
            'total'    => (clone $base())->count(),
        ];

        $columns = [
            ['key' => 'contact', 'label' => 'Contact', 'type' => 'avatar', 'sortable' => true],
            // v4.94 — sortable so the whole website inbox can be pulled to the top in one click.
            ['key' => 'channel', 'label' => 'Channel', 'type' => 'badge', 'sortable' => true],
            ['key' => 'last', 'label' => 'Last message'],
            ['key' => 'unread', 'label' => 'Unread', 'type' => 'badge', 'align' => 'right', 'sortable' => true],
            ['key' => 'mode', 'label' => 'Mode', 'type' => 'badge', 'sortable' => true],
            ['key' => 'when', 'label' => 'Activity', 'align' => 'right', 'type' => 'num', 'sortable' => true],
        ];

        $actions = [];
        if ($canChat) {
            $actions[] = ['key' => 'new_chat', 'label' => 'New message', 'queryWrite' => true, 'method' => 'POST',
                'endpoint' => '/papi/chats/send', 'actor' => 'human', 'tone' => 'primary',
                'schema' => ['phone' => 'text', 'body' => 'text'],
                'labels' => ['phone' => 'WhatsApp number (digits, e.g. 2567…)', 'body' => 'Message'],
            ];
            $actions[] = ['key' => 'wa_health', 'label' => 'Check connection now', 'method' => 'GET',
                'endpoint' => '/papi/wa/health', 'actor' => 'human', 'icon' => 'refresh'];
            $actions[] = ['key' => 'sync', 'label' => 'Sync contacts', 'queryWrite' => true, 'method' => 'POST',
                'endpoint' => '/papi/chats/sync', 'actor' => 'human', 'icon' => 'export',
                'confirm' => 'Pull the latest conversations from WhatsApp now?'];
        }

        // WhatsApp health chip (2026-07-18): the connection status finally VISIBLE where
        // the team lives. Settings are maintained by wa:watchdog; a 60s-cached live probe
        // keeps it honest between watchdog runs. Guarded — a probe failure never breaks Chats.
        $waT = \App\Models\Tenant::find($ctx->tenantId());
        $waStatus = (string) ($waT?->setting('wa_status', 'open') ?? 'open');
        $waLastOk = (string) ($waT?->setting('wa_last_ok', '') ?? '');
        try {
            $inst = (string) ($waT?->whatsapp_instance ?? '');
            if ($inst !== '') {
                $live = \Illuminate\Support\Facades\Cache::remember('wa_live:'.$ctx->tenantId(), 60,
                    fn () => app(\App\Services\WhatsApp\EvolutionAdmin::class)->state($inst));
                if ($live !== '') $waStatus = $live === 'open' ? 'open' : ($live === 'connecting' ? 'connecting' : 'down');
            }
        } catch (\Throwable $e) {
        }
        $waChip = ['key' => 'wa', 'label' => 'WhatsApp',
            'value' => $waStatus === 'open' ? 'Connected' : ($waStatus === 'connecting' ? 'Connecting…' : 'DOWN'),
            'tone' => $waStatus === 'open' ? 'ok' : ($waStatus === 'connecting' ? 'warn' : 'danger'),
            'foot' => $waLastOk !== '' ? 'last OK '.\Illuminate\Support\Carbon::parse($waLastOk)->diffForHumans() : 'checked live'];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:chats', 'title' => 'Chats',
                'subtitle' => number_format($stats['total']).' conversations · '.$stats['unread'].' unread',
            ])
            ->financials([
                $waChip,
                ['key' => 'unread', 'label' => 'Unread', 'value' => (string) $stats['unread'], 'tone' => $stats['unread'] > 0 ? 'danger' : 'ok', 'foot' => 'need a reply'],
                ['key' => 'agent', 'label' => 'Agent mode', 'value' => (string) $stats['agent'], 'tone' => $stats['agent'] > 0 ? 'warn' : 'default', 'foot' => 'bot paused, human driving'],
                ['key' => 'today', 'label' => 'Active today', 'value' => (string) $stats['today'], 'foot' => 'any direction'],
                ['key' => 'inbound', 'label' => 'Customers wrote today', 'value' => (string) $stats['inbound'], 'tone' => 'info', 'foot' => 'inbound since midnight'],
            ])
            ->table($columns, $rows,
                [
                    ['key' => 'state', 'label' => 'State'],
                    ['key' => 'mode', 'label' => 'Mode'],
                ],
                ['pageSize' => 25])
            ->actions($actions)
            ->meta(['capability' => 'chats', 'ttl' => 15, 'permissions' => ['menu:whatsapp']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenantId();
        $base = fn () => Conversation::where('tenant_id', $t);

        return [
            'unread' => (clone $base())->where('unread', '>', 0)->count(),
            'agent'  => (clone $base())->where('agent_active', true)->count(),
            'today'  => (clone $base())->where('last_message_at', '>=', now()->startOfDay())->count(),
        ];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
