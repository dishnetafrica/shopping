<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\Brief;
use App\Capability\Dto\WorkspacePayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Facebook Ads — the v3 face of the panel's Ads screen. It does NOT manage real Facebook ad
 * campaigns; it controls how the WhatsApp bot handles people who arrive from an ad, plus the
 * "virtual salesperson" follow-up cadence, and shows recent ad arrivals.
 *
 * Reads project directly from tenant settings + the bot_events trace (allowed for a Process
 * capability). Every WRITE goes through the frozen, proven panel endpoints — /papi/ads-toggle
 * and /papi/ads-settings — so there is no new business logic and nothing in the engine changes.
 */
final class AdsCapability implements ProcessCapability
{
    public function key(): string { return 'ads'; }

    public function permission(): string { return ''; } // visible to staff; editing gated to owner/manager below

    private function privileged(CapabilityContext $ctx): bool
    {
        $u = $ctx->user;
        if (method_exists($u, 'isOwnerLike') && $u->isOwnerLike()) { return true; }

        return in_array((string) ($u->role ?? ''), ['manager'], true);
    }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenant;
        $canEdit = $this->privileged($ctx);
        $yn = fn ($b) => $b ? 'Yes' : 'No';
        $onoff = fn ($b) => $b ? 'On' : 'Off';

        $enabled = (bool) $t->setting('bot_ad_awareness', true);
        $botMode = (string) $t->setting('bot_mode', 'auto');
        $modeNice = ['ai' => 'AI brain', 'n8n' => 'n8n workflow', 'auto' => 'Inbuilt bot', 'inbuilt' => 'Inbuilt bot'][$botMode] ?? $botMode;

        // --- bot behaviour settings ---
        $adIgnore = (string) $t->setting('ad_ignore_titles', '');
        $keepAi = ! (bool) $t->setting('human_takeover_on_reply', true); // inverse, exactly like the panel
        $warehouse = (string) $t->setting('warehouse_location', '');
        $moqNote = (string) $t->setting('moq_note', '');
        $basisCity = (string) $t->setting('export_basis_city', '');

        // --- virtual salesperson ---
        $salesOn = (bool) $t->setting('sales_followup_enabled', false);
        $salesBuyers = (bool) $t->setting('sales_include_buyers', true);
        $salesReorderDays = (int) $t->setting('sales_reorder_days', 21);
        $salesMsgDay1 = (string) $t->setting('sales_msg_day1', '');
        $salesMsgReorder = (string) $t->setting('sales_msg_reorder', '');

        // --- ad arrivals from the bot_events trace ---
        $eventRows = []; $count7 = 0;
        try {
            $rows = DB::table('bot_events')->where('tenant_id', $t->id)
                ->whereIn('stage', ['ad_referral', 'ad_followup'])
                ->orderByDesc('id')->limit(80)->get();
            foreach ($rows as $r) {
                $when = $r->created_at ? Carbon::parse($r->created_at) : null;
                $isRef = (string) $r->stage === 'ad_referral';
                $eventRows[] = ['cells' => [
                    $when ? $when->diffForHumans() : '—',
                    (string) ($r->phone ?: '—'),
                    ['value' => $isRef ? 'From ad' : 'Follow-up', 'badge' => $isRef ? 'info' : 'accent'],
                    (string) ($r->detail ?: '—'),
                ], 'ts' => $when ? $when->timestamp : 0, 'link' => null];
            }
            $count7 = (int) DB::table('bot_events')->where('tenant_id', $t->id)
                ->where('stage', 'ad_referral')->where('created_at', '>=', now()->subDays(7))->count();
        } catch (\Throwable $e) { /* bot_events table optional */ }

        // ---- write actions (owner/manager only) ----
        $toggle = ['key' => 'toggle_ads', 'label' => $enabled ? 'Turn OFF' : 'Turn ON', 'method' => 'POST', 'endpoint' => '/papi/ads-toggle',
            'actor' => 'human', 'tone' => $enabled ? 'default' : 'primary', 'body' => ['on' => $enabled ? 0 : 1],
            'confirm' => $enabled ? 'Stop the bot from understanding ad customers?' : 'Let the bot understand customers who arrive from your Facebook/Instagram ads?'];

        $editBehaviour = ['key' => 'edit_behaviour', 'label' => 'Edit behaviour', 'method' => 'POST', 'endpoint' => '/papi/ads-settings', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['ad_ignore_titles' => 'text', 'keep_ai_on' => 'enum:Yes,No', 'basis_city' => 'text', 'warehouse' => 'text', 'moq_note' => 'text'],
            'labels' => [
                'ad_ignore_titles' => 'Ad page / brand names to ignore (comma-separated)',
                'keep_ai_on' => 'Keep AI replying after I message a customer',
                'basis_city' => 'Price basis city (USD prices are ex-…)',
                'warehouse' => 'Warehouse / pickup location',
                'moq_note' => 'Minimum-order note (optional)',
            ],
            'prefill' => ['ad_ignore_titles' => $adIgnore, 'keep_ai_on' => $yn($keepAi), 'basis_city' => $basisCity, 'warehouse' => $warehouse, 'moq_note' => $moqNote]];

        $editSales = ['key' => 'edit_salesperson', 'label' => 'Edit salesperson', 'method' => 'POST', 'endpoint' => '/papi/ads-settings', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['sales_on' => 'enum:Yes,No', 'sales_buyers' => 'enum:Yes,No', 'sales_reorder_days' => 'number', 'sales_msg_day1' => 'text', 'sales_msg_reorder' => 'text'],
            'labels' => [
                'sales_on' => 'Virtual salesperson (auto follow-up) on',
                'sales_buyers' => 'Also remind past buyers to re-order',
                'sales_reorder_days' => 'Re-order reminder after (days)',
                'sales_msg_day1' => 'Day-1 message — {name}, {items}',
                'sales_msg_reorder' => 'Re-order message — {name}, {days}, {items}',
            ],
            'prefill' => ['sales_on' => $yn($salesOn), 'sales_buyers' => $yn($salesBuyers), 'sales_reorder_days' => $salesReorderDays, 'sales_msg_day1' => $salesMsgDay1, 'sales_msg_reorder' => $salesMsgReorder]];

        $header = $canEdit ? [$toggle, $editBehaviour, $editSales] : [];
        $quick = $canEdit ? [$toggle, $editBehaviour, $editSales] : [];

        // ---- cards ----
        $howCard = ['kind' => 'dl', 'title' => 'How it works', 'rows' => [
            ['When it is ON', 'A customer who taps or forwards your Facebook/Instagram ad gets an answer about the advertised product — not a generic reply.'],
            ['Ad understanding', $onoff($enabled)],
            ['Bot mode', $modeNice],
            ['Ad arrivals (7 days)', (string) $count7],
            ['Virtual salesperson', $onoff($salesOn)],
        ]];

        $behaviourCard = ['kind' => 'dl', 'title' => 'Bot behaviour', 'sub' => $canEdit ? 'Use “Edit behaviour” to change' : 'Owner/manager only', 'rows' => [
            ['Ad names ignored', $adIgnore !== '' ? $adIgnore : '— (only your business name)'],
            ['Keep AI on after manual message', $yn($keepAi)],
            ['Price basis city', $basisCity !== '' ? $basisCity : '—'],
            ['Warehouse / pickup', $warehouse !== '' ? $warehouse : '—'],
            ['Minimum-order note', $moqNote !== '' ? $moqNote : 'Default carton wording'],
        ]];

        $salesCard = ['kind' => 'dl', 'title' => 'Virtual salesperson', 'sub' => 'Day 1 follow-up · Day 3 price list · Day 7 check-in · then fortnightly. Stops on reply/order. 9am–7pm EAT.', 'rows' => [
            ['Auto follow-up', $onoff($salesOn)],
            ['Remind past buyers to re-order', $yn($salesBuyers)],
            ['Re-order reminder after', $salesReorderDays . ' days'],
            ['Day-1 message', $salesMsgDay1 !== '' ? $salesMsgDay1 : 'Default'],
            ['Re-order message', $salesMsgReorder !== '' ? $salesMsgReorder : 'Default'],
        ]];

        $arrivalsCard = ['kind' => 'table', 'title' => 'Recent ad arrivals', 'sub' => count($eventRows) . ' shown', 'searchable' => true,
            'columns' => [['label' => 'When'], ['label' => 'Phone'], ['label' => 'Type'], ['label' => 'What the bot matched']],
            'rows' => $eventRows, 'empty' => 'No ad arrivals yet — when a customer messages from your Facebook ad they appear here with the product the bot matched.', 'pageSize' => 25];

        return (new WorkspacePayload)
            ->object([
                'type' => 'process:ads', 'title' => 'Facebook Ads',
                'subtitle' => 'How the WhatsApp bot handles ad customers · ' . $count7 . ' arrival' . ($count7 === 1 ? '' : 's') . ' in 7 days',
                'status' => $enabled ? 'active' : 'inactive',
            ])
            ->headline($enabled ? 'ON' : 'OFF', 'Ad understanding', $enabled ? 'ok' : 'warn')
            ->brief(new Brief($enabled ? 'low' : 'medium',
                $enabled
                    ? 'The bot understands customers arriving from your Facebook/Instagram ads and answers about the advertised product.'
                    : 'Ad understanding is OFF — customers from ads get generic replies. Turn it on so the bot answers about what they clicked.',
                'Control the bot’s ad handling, the small behaviour fixes, and the virtual-salesperson follow-ups. Writes go through the same panel endpoints you already use.',
                ['tone' => $enabled ? 'ok' : 'warn', 'label' => $enabled ? 'Understanding ON' : 'Understanding OFF']))
            ->financials([
                ['key' => 'arrivals', 'label' => 'Ad arrivals (7d)', 'value' => (string) $count7, 'foot' => 'from Facebook/Instagram'],
                ['key' => 'state', 'label' => 'Ad understanding', 'value' => $enabled ? 'On' : 'Off', 'tone' => $enabled ? 'ok' : 'warn'],
                ['key' => 'mode', 'label' => 'Bot mode', 'value' => $modeNice],
                ['key' => 'sales', 'label' => 'Virtual salesperson', 'value' => $onoff($salesOn), 'tone' => $salesOn ? 'ok' : 'default'],
            ])
            ->quickActions($quick)
            ->tabSections([
                'overview' => [$howCard],
                'behaviour' => [$behaviourCard],
                'salesperson' => [$salesCard],
                'arrivals' => [$arrivalsCard],
            ])
            ->tabs([
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'behaviour', 'label' => 'Bot behaviour'],
                ['key' => 'salesperson', 'label' => 'Virtual salesperson'],
                ['key' => 'arrivals', 'label' => 'Ad arrivals', 'count' => count($eventRows)],
            ])
            ->actions($header)
            ->meta(['capability' => 'ads', 'listKey' => 'ads', 'ttl' => 20, 'layout' => 'record', 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $t = $ctx->tenant;
        $count7 = 0;
        try {
            $count7 = (int) DB::table('bot_events')->where('tenant_id', $t->id)
                ->where('stage', 'ad_referral')->where('created_at', '>=', now()->subDays(7))->count();
        } catch (\Throwable $e) {}

        return ['enabled' => (bool) $t->setting('bot_ad_awareness', true), 'arrivals7d' => $count7];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
