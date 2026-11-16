<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\Brain\Messages;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;

/**
 * Sales Brain — the V3 list of governed sales knowledge (DishNet South Sudan plan, Phase 3).
 * Internet-provider businesses only. Owners/managers see every entry with what is live and what is
 * waiting; anyone else sees published knowledge only. Writes go through SalesBrain.
 */
final class SalesBrainListCapability implements ProcessCapability
{
    public function key(): string { return 'salesBrain'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! SalesBrain::ready()) {
            return (new WorkspacePayload)
                ->object(['type' => 'process:salesBrain', 'title' => 'Sales Brain', 'subtitle' => 'Available for Internet providers'])
                ->table([['key' => 'entry', 'label' => 'Knowledge']], [], [], ['pageSize' => 20])
                ->meta(['capability' => 'salesBrain', 'ttl' => 300, 'permissions' => []]);
        }
        $manage = SalesOwnership::canManage($ctx->user);
        $entries = SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->orderBy('topic')->orderBy('key')->get();
        $versions = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())
            ->whereIn('status', ['draft', 'approved', 'published'])->get()->groupBy('entry_id');

        $rows = [];
        $waiting = 0; $questions = 0; $liveTopics = [];
        foreach ($entries as $e) {
            $vs = $versions->get($e->id, collect());
            $live = $vs->firstWhere('status', 'published');
            $pending = $vs->first(fn ($v) => in_array($v->status, ['draft', 'approved'], true));
            if (! $manage && (! $live || in_array($e->topic, Knowledge::INTERNAL, true))) {
                continue;                                    // staff see published knowledge only, never training answers
            }
            if ($live) {
                $liveTopics[$e->topic] = true;
            }
            $open = $pending ? count((array) $pending->open_questions) : 0;
            $waiting += $pending ? 1 : 0;
            $questions += $open;
            $rows[] = [
                'entry'   => ['value' => $e->title, 'sub' => (Knowledge::TOPICS[$e->topic] ?? $e->topic) . ' · ' . $e->key, 'avatar' => true],
                'live'    => $live
                    ? ['value' => 'Version ' . $live->version . ' · ' . ($live->published_at?->format('j M Y') ?? ''), 'badge' => 'ok', 'sort' => '1']
                    : ['value' => 'Not published', 'badge' => 'default', 'sort' => '0'],
                'pending' => ! $manage || ! $pending ? ['value' => '—', 'sort' => '0'] : ($pending->status === 'approved'
                    ? ['value' => 'Version ' . $pending->version . ' approved — ready to publish', 'badge' => 'info', 'sort' => '2']
                    : ['value' => 'Draft version ' . $pending->version . ($open ? ' · ' . $open . ' open question' . ($open === 1 ? '' : 's') : ' · ready to approve'),
                        'badge' => $open ? 'warn' : 'info', 'sort' => '1']),
                'topic'   => ['value' => Knowledge::TOPICS[$e->topic] ?? $e->topic, 'sort' => $e->topic],
                'updated' => ['value' => $e->updated_at ? $e->updated_at->diffForHumans() : '—', 'sort' => (string) ($e->updated_at?->getTimestamp() ?? 0)],
                'link'    => '/api/v1/workspace/salesKnowledge/' . $e->id,
            ];
        }

        $essential = ['packages', 'coverage', 'terms', 'payment'];
        $missing = array_values(array_filter($essential, fn ($t) => ! isset($liveTopics[$t])));
        $cards = [
            ['key' => 'live', 'label' => 'Published', 'value' => (string) count($liveTopics) . ' of ' . count(Knowledge::TOPICS) . ' topics', 'foot' => 'what the bot, the coach and quotations use'],
        ];
        if ($manage) {
            $cards[] = ['key' => 'waiting', 'label' => 'Waiting', 'value' => (string) $waiting, 'tone' => $waiting ? 'warn' : 'ok', 'foot' => 'drafts and approved versions not yet live'];
            $cards[] = ['key' => 'questions', 'label' => 'Open questions', 'value' => (string) $questions, 'tone' => $questions ? 'warn' : 'ok', 'foot' => 'must be answered before approval'];
        }
        $cards[] = ['key' => 'missing', 'label' => 'Essentials not published', 'value' => (string) count($missing), 'tone' => $missing ? 'danger' : 'ok',
            'foot' => $missing ? implode(', ', array_map(fn ($t) => Knowledge::TOPICS[$t], $missing)) . ' — the bot will not quote or confirm these' : 'packages, coverage, terms and payment are live'];

        $actions = $manage ? [[
            'key' => 'new_entry', 'label' => 'New knowledge', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/salesBrain/_/new_entry',
            'actor' => 'human', 'tone' => 'primary', 'submitLabel' => 'Save as draft',
            'schema' => ['topic' => 'enum:' . implode(',', array_keys(Knowledge::TOPICS)), 'title' => 'text', 'key' => 'text', 'content' => 'textarea', 'source' => 'text', 'note' => 'text'],
            'labels' => [
                'topic' => 'Topic', 'title' => 'Title (e.g. Starlink terms)', 'key' => 'Short name (e.g. starlink); same topic + short name = same entry',
                'content' => 'Content. Terms: one per line, "Label: text". Coverage: one area per line, "Name | starlink or fiber | covered, survey or none | place names, comma separated | note". Training questions: short name = the module (product, qualification, communication, quotation, service); "Q: question", options on their own lines with "* " before the correct one and "- " before the others, a blank line between questions',
                'source' => 'Where this came from (optional)', 'note' => 'Change note (optional)',
            ],
        ]] : [];

        return (new WorkspacePayload)
            ->object(['type' => 'process:salesBrain', 'title' => 'Sales Brain',
                'subtitle' => $manage ? 'Sales knowledge for this market. Only published versions reach customers, the coach and quotations.' : 'Approved sales knowledge'])
            ->financials($cards)
            ->table(
                [
                    ['key' => 'entry', 'label' => 'Knowledge', 'type' => 'avatar', 'sortable' => true],
                    ['key' => 'live', 'label' => 'Live', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'pending', 'label' => 'Waiting', 'type' => 'badge', 'sortable' => true],
                    ['key' => 'topic', 'label' => 'Topic', 'sortable' => true],
                    ['key' => 'updated', 'label' => 'Updated', 'sortable' => true],
                ],
                $rows, [['key' => 'topic', 'label' => 'Topic']], ['pageSize' => 30])
            ->actions($actions)
            ->meta(['capability' => 'salesBrain', 'ttl' => 20, 'permissions' => []]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        if (! SalesOwnership::enabled($ctx->tenant) || ! SalesBrain::ready()) {
            return ['published' => 0, 'waiting' => 0];
        }
        $q = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId());

        return ['published' => (clone $q)->where('status', 'published')->count(), 'waiting' => (clone $q)->whereIn('status', ['draft', 'approved'])->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action !== 'new_entry') {
            return ['ok' => false, 'error' => 'unknown_action'];
        }
        if (! SalesOwnership::enabled($ctx->tenant)) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        $topic = (string) ($input['topic'] ?? '');
        $title = trim((string) ($input['title'] ?? ''));
        [$body, $data] = Knowledge::fromText($topic, (string) ($input['content'] ?? ''), $title, (string) (($input['key'] ?? '') ?: $title));
        $r = app(SalesBrain::class)->startVersion($ctx->tenant, $ctx->user, $topic, (string) (($input['key'] ?? '') ?: $title), $title, $body, $data,
            trim((string) ($input['source'] ?? '')) ?: null, [], trim((string) ($input['note'] ?? '')) ?: null);

        return $r['ok']
            ? ['ok' => true, 'message' => 'Saved as draft version ' . $r['version'] . '. It is not live until approved and published.', 'redirect' => '/api/v1/workspace/salesKnowledge/' . $r['entry_id']]
            : $r + ['message' => Messages::error($r)];
    }
}

