<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\BusinessSuggestion;
use App\Services\Discovery\BusinessReview;
use App\Services\Discovery\DiscoveryQueue;
use App\Services\Discovery\DiscoveryService;

/**
 * v4.62 — Businesses found: the discovery review queue.
 *
 * Nothing a provider finds is written to a customer record. It waits here until a person says yes.
 *
 * The screen is built around the one question a reviewer is actually answering — *do I want this
 * business in my system?* — so a row is one business, not one field. Every proposed value is shown
 * with where it came from and how sure that source was, because a reviewer who can only accept or
 * reject learns nothing, while a reviewer who can see "phone, from your spreadsheet, row 14" can
 * disagree with it.
 *
 * Two labels do a lot of work here. **New** means approving creates a record. **Fills gaps** means
 * the business is already a customer and approving only fills fields that are still empty — never
 * one that has anything in it, whoever put it there.
 */
final class DiscoveryCapability implements ProcessCapability
{
    private const MAX_ROWS = 200;

    public function key(): string { return 'discovery'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();

        $pending = BusinessSuggestion::withoutGlobalScopes()
            ->where('tenant_id', $t)->where('status', 'pending')
            ->orderByDesc('confidence')->orderBy('id')
            ->limit(self::MAX_ROWS)->get();

        $new = 0; $enrich = 0; $reachable = 0;
        $rows = [];

        foreach ($pending as $s) {
            $isNew = $s->customer_profile_id === null;
            $isNew ? $new++ : $enrich++;
            if (trim((string) $s->phone) !== '') {
                $reachable++;
            }

            $facts = [];
            foreach ((array) ($s->findings ?? []) as $f) {
                $facts[] = ucfirst(str_replace('_', ' ', (string) ($f['field'] ?? ''))) . ': ' . (string) ($f['value'] ?? '');
            }
            $conf = (float) $s->confidence;

            $rows[] = [
                'business' => ['value' => (string) ($s->name ?: $s->phone ?: 'Unnamed'),
                               'sub' => trim((string) $s->city) ?: (string) $s->phone, 'avatar' => true],
                'kind'     => ['value' => $isNew ? 'New' : 'Fills gaps', 'badge' => $isNew ? 'ok' : 'info'],
                'facts'    => ['value' => implode(' · ', array_slice($facts, 0, 4))
                                          . (count($facts) > 4 ? ' · +' . (count($facts) - 4) . ' more' : '')],
                'phone'    => ['value' => trim((string) $s->phone) !== '' ? (string) $s->phone : 'no number'],
                'source'   => ['value' => (string) ($s->provider ?: '—')],
                // The same three words the Growth screen uses, so "Fair" means the same thing on
                // both and nobody has to learn a second scale.
                'trust'    => ['value' => $conf >= 0.85 ? 'High' : ($conf >= 0.6 ? 'Fair' : 'Low'),
                               'badge' => $conf >= 0.85 ? 'ok' : ($conf >= 0.6 ? 'warn' : 'muted')],
                'act'      => ['buttons' => [
                    ['label' => 'Add', 'icon' => 'check', 'post' => '/api/v1/workspace/discovery/command',
                     'body' => ['action' => 'approve', 'id' => (int) $s->id]],
                    ['label' => 'No', 'icon' => 'ban', 'post' => '/api/v1/workspace/discovery/command',
                     'body' => ['action' => 'reject', 'id' => (int) $s->id]],
                ]],
                'id'       => (int) $s->id,
            ];
        }

        $columns = [
            ['key' => 'business', 'label' => 'Business', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'kind',     'label' => 'What happens', 'type' => 'badge', 'sortable' => true],
            ['key' => 'facts',    'label' => 'What it would add', 'sortable' => false],
            ['key' => 'phone',    'label' => 'Phone', 'sortable' => true],
            ['key' => 'source',   'label' => 'Found by', 'type' => 'badge', 'sortable' => true],
            ['key' => 'trust',    'label' => 'Confidence', 'type' => 'badge', 'sortable' => true],
            ['key' => 'act',      'label' => '', 'sortable' => false],
        ];

        // A business with no phone cannot be invoiced, messaged or put on a statement — every one of
        // those paths in this system is phone-keyed. That is not a bug to hide; it is the next job,
        // and it belongs to a rep rather than to a provider.
        $noPhone = count($rows) - $reachable;

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:discovery', 'title' => 'Businesses found',
                'subtitle' => count($rows) . ' waiting · nothing is added to your customers until you say so',
            ])
            ->headline((string) count($rows), 'waiting for a decision', count($rows) > 0 ? 'warn' : 'ok')
            ->financials([
                ['key' => 'new',    'label' => 'New businesses', 'value' => $new,    'foot' => 'not in your system yet'],
                ['key' => 'enrich', 'label' => 'Already yours',  'value' => $enrich, 'foot' => 'would only fill empty fields'],
                ['key' => 'phone',  'label' => 'With a number',  'value' => $reachable,
                 'foot' => $noPhone > 0 ? $noPhone . ' cannot be contacted yet' : 'all reachable'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'discovery', 'ttl' => 60, 'permissions' => ['menu:customers']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['waiting' => BusinessSuggestion::withoutGlobalScopes()
            ->where('tenant_id', $ctx->tenantId())->where('status', 'pending')->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        $uid = (int) ($ctx->user->id ?? 0);

        if ($action === 'approve' || $action === 'reject') {
            $s = BusinessSuggestion::withoutGlobalScopes()
                ->where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
            if (! $s) {
                return ['ok' => false, 'error' => 'That row is no longer here.'];
            }
            if ($action === 'reject') {
                BusinessReview::dismissOne($s, $uid);

                return ['ok' => true, 'message' => 'Removed from the queue.'];
            }

            $r = BusinessReview::applyOne($s, $uid);

            return $r['ok']
                ? ['ok' => true, 'message' => 'Added — ' . $r['wrote'] . ' field(s) written.']
                // Named rather than a bare failure: "already_filled" is the safety rule working,
                // and a reviewer who is told that trusts the queue more, not less.
                : ['ok' => true, 'message' => match ($r['reason']) {
                    'already_filled'  => 'Nothing written — somebody had already filled those in.',
                    'nothing_to_write' => 'Nothing left to add. Removed from the queue.',
                    'already_decided' => 'That one was already decided.',
                    default           => 'Not added (' . $r['reason'] . ').',
                }];
        }

        if ($action === 'approve_all') {
            $rows = BusinessSuggestion::withoutGlobalScopes()
                ->where('tenant_id', $ctx->tenantId())->where('status', 'pending')->get();
            $r = BusinessReview::applyMany($rows, $uid);

            return ['ok' => true, 'message' => $r['applied'] . ' added (' . $r['created'] . ' new)'
                . ($r['skipped'] > 0 ? ', ' . $r['skipped'] . ' skipped' : '') . '.'];
        }

        if ($action === 'import_csv') {
            $csv = (string) ($input['csv'] ?? '');
            if (trim($csv) === '') {
                return ['ok' => false, 'error' => 'Paste a spreadsheet first.'];
            }
            $res = app(DiscoveryService::class)->run($ctx->tenant, ['csv' => $csv], $uid, ['csv']);
            $q   = app(DiscoveryQueue::class)->fill($ctx->tenant, $res['businesses'], 'csv');

            return ['ok' => true, 'message' => $q['created'] . ' new, ' . $q['updated'] . ' updated, '
                . $q['matched'] . ' already customers'
                . ($q['skipped'] > 0 ? ', ' . $q['skipped'] . ' skipped' : '') . '.'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
