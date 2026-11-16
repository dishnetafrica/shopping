<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Sales\Insights\Metrics;
use App\Services\Sales\Insights\SalesInsights;
use App\Services\Sales\SalesOwnership;

/**
 * Quality monitoring (DishNet South Sudan plan, Phase 8) — owners and managers. One standard for the
 * bot and for people: no price outside the catalogue or approved knowledge, no coverage claim that
 * management has not published. Shows what the bot's and the coach's guard removed before sending,
 * and lists salespeople's sent replies that broke the same rules, plus customers left waiting.
 */
final class SalesQualityCapability implements ProcessCapability
{
    public function key(): string { return 'salesQuality'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $empty = fn (string $why) => (new WorkspacePayload)
            ->object(['type' => 'process:salesQuality', 'title' => 'Quality monitoring', 'subtitle' => $why])
            ->table([['key' => 'what', 'label' => 'Finding']], [], [], ['pageSize' => 20])
            ->meta(['capability' => 'salesQuality', 'ttl' => 300, 'permissions' => []]);
        if (! SalesOwnership::enabled($ctx->tenant)) {
            return $empty('Available for Internet providers');
        }
        if (! SalesOwnership::canManage($ctx->user)) {
            return $empty('For owners and managers');
        }
        $q = app(SalesInsights::class)->quality($ctx->tenant);
        $names = User::where('tenant_id', $ctx->tenantId())->pluck('name', 'id')->all();
        $tz = (string) ($ctx->tenant->setting('timezone') ?: config('app.timezone'));
        $convs = Conversation::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())
            ->whereIn('customer_phone', array_unique(array_merge(array_column($q['flags'], 'phone'), array_column($q['waiting'], 'phone'))))
            ->pluck('id', 'customer_phone')->all();

        $rows = [];
        foreach ($q['flags'] as $f) {
            $rows[] = [
                'what'   => ['value' => $f['kind'] === 'price' ? 'Price not approved' : 'Coverage not published', 'badge' => 'danger', 'sort' => $f['kind']],
                'detail' => ['value' => '"' . mb_substr($f['sentence'], 0, 200) . '"', 'sub' => '+' . $f['phone']],
                'who'    => ['value' => $f['user_id'] ? ($names[$f['user_id']] ?? 'Staff') : 'Staff (not recorded)', 'sort' => (string) ($names[$f['user_id']] ?? '')],
                'when'   => ['value' => $f['at']->copy()->setTimezone($tz)->format('j M, H:i'), 'sort' => (string) $f['at']->getTimestamp()],
                'link'   => isset($convs[$f['phone']]) ? '/api/v1/workspace/chat/' . $convs[$f['phone']] : null,
            ];
        }
        foreach ($q['waiting'] as $w) {
            $rows[] = [
                'what'   => ['value' => 'Customer waiting', 'badge' => $w['hours'] >= 24 ? 'danger' : 'warn', 'sort' => 'waiting'],
                'detail' => ['value' => 'No reply for ' . Metrics::duration($w['hours'] * 60), 'sub' => '+' . $w['phone']],
                'who'    => ['value' => $w['user_id'] ? ($names[$w['user_id']] ?? 'Staff') : 'Unassigned', 'sort' => (string) ($names[$w['user_id']] ?? '')],
                'when'   => ['value' => $w['since']->copy()->setTimezone($tz)->format('j M, H:i'), 'sort' => (string) $w['since']->getTimestamp()],
                'link'   => '/api/v1/workspace/chat/' . $w['conversation_id'],
            ];
        }
        $rows = array_map(fn ($r) => array_filter($r, fn ($v) => $v !== null), $rows);

        $bot = $q['bot'];
        $flagsBy = array_count_values(array_column($q['flags'], 'kind'));

        return (new WorkspacePayload)
            ->object(['type' => 'process:salesQuality', 'title' => 'Quality monitoring',
                'subtitle' => 'Last ' . Metrics::DAYS . ' days · the same rules for the bot and for people: only catalogue or approved prices, only published coverage'])
            ->financials([
                ['key' => 'bot', 'label' => 'Bot: corrected before sending', 'value' => (string) ($bot['isp_price_stripped'] + $bot['isp_cover_stripped']),
                    'tone' => ($bot['isp_price_stripped'] + $bot['isp_cover_stripped']) ? 'warn' : 'ok',
                    'foot' => $bot['isp_price_stripped'] . ' price · ' . $bot['isp_cover_stripped'] . ' coverage sentences removed'],
                ['key' => 'settings', 'label' => 'Unapproved settings kept from the bot', 'value' => (string) $bot['isp_unapproved_skipped'],
                    'tone' => $bot['isp_unapproved_skipped'] ? 'warn' : 'ok', 'foot' => $bot['isp_unapproved_skipped'] ? 'move that text into Sales Brain' : 'none'],
                ['key' => 'coach', 'label' => 'Coach: suggestions corrected', 'value' => $q['coach_corrected'] . ' of ' . $q['coach_total']],
                ['key' => 'people', 'label' => 'Replies by people to review', 'value' => (string) count($q['flags']), 'tone' => $q['flags'] ? 'danger' : 'ok',
                    'foot' => ($flagsBy['price'] ?? 0) . ' price · ' . ($flagsBy['coverage'] ?? 0) . ' coverage · ' . $q['checked_replies'] . ' replies checked'],
                ['key' => 'waiting', 'label' => 'Customers waiting', 'value' => (string) count($q['waiting']), 'tone' => $q['waiting'] ? 'danger' : 'ok',
                    'foot' => 'last message theirs, ' . Metrics::WAITING_HOURS . 'h+ ago'],
            ])
            ->table([
                ['key' => 'what', 'label' => 'Finding', 'type' => 'badge', 'sortable' => true],
                ['key' => 'detail', 'label' => 'Detail'],
                ['key' => 'who', 'label' => 'Salesperson', 'sortable' => true],
                ['key' => 'when', 'label' => 'When', 'sortable' => true],
            ], $rows, [['key' => 'what', 'label' => 'Finding'], ['key' => 'who', 'label' => 'Salesperson']], ['pageSize' => 30])
            ->actions([['key' => 'dashboard', 'label' => 'Sales dashboard', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/salesDashboard', 'actor' => 'human']])
            ->meta(['capability' => 'salesQuality', 'ttl' => 60, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return [];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
