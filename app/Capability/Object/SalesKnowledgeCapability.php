<?php

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\TimelineEvent;
use App\Capability\Dto\WorkspacePayload;
use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\User;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\Brain\Messages;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;

/**
 * One piece of Sales Brain knowledge — the V3 detail: what is live, the version waiting (with its open
 * questions), every past version, and the management steps (edit draft, answer a question, approve,
 * publish, reject, start a new version, retire). Every write goes through SalesBrain.
 * Staff who are not owners/managers see the live version only.
 */
final class SalesKnowledgeCapability implements ObjectCapability
{
    private const STATUS = ['draft' => ['Draft', 'warn'], 'approved' => ['Approved — not yet live', 'info'], 'published' => ['Live', 'ok'],
        'retired' => ['Retired', 'default'], 'rejected' => ['Rejected', 'danger']];

    public function key(): string { return 'salesKnowledge'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        return (new WorkspacePayload)->object(['type' => 'salesKnowledge'])->meta(['capability' => 'salesKnowledge']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $e = SalesOwnership::enabled($ctx->tenant) && SalesBrain::ready()
            ? SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->find((int) $id) : null;
        $manage = SalesOwnership::canManage($ctx->user);
        $versions = $e ? SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('entry_id', $e->id)->orderByDesc('version')->get() : collect();
        $live = $versions->firstWhere('status', 'published');
        if (! $e || (! $manage && (! $live || in_array($e->topic, Knowledge::INTERNAL, true)))) {   // quiz answers: managers only
            return (new WorkspacePayload)->object(['type' => 'salesKnowledge', 'title' => 'Not found'])->meta(['capability' => 'salesKnowledge', 'error' => 'not_found']);
        }
        $pending = $manage ? $versions->first(fn ($v) => in_array($v->status, ['draft', 'approved'], true)) : null;
        $names = User::where('tenant_id', $ctx->tenantId())->pluck('name', 'id')->all();
        $who = fn ($uid) => $uid ? ($names[$uid] ?? 'Staff') : 'System import';
        $topic = Knowledge::TOPICS[$e->topic] ?? $e->topic;
        $open = $pending ? array_values((array) $pending->open_questions) : [];

        $stats = [
            ['key' => 'live', 'label' => 'Live', 'value' => $live ? 'Version ' . $live->version : 'Not published', 'icon' => 'flag', 'tone' => $live ? 'ok' : 'warn'],
            ['key' => 'topic', 'label' => 'Topic', 'value' => $topic, 'icon' => 'doc'],
        ];
        if ($manage) {
            $stats[] = ['key' => 'waiting', 'label' => 'Waiting', 'icon' => 'clock', 'tone' => $pending ? 'warn' : 'default',
                'value' => $pending ? 'Version ' . $pending->version . ' · ' . self::STATUS[$pending->status][0] : 'Nothing'];
            $stats[] = ['key' => 'questions', 'label' => 'Open questions', 'value' => (string) count($open), 'icon' => 'flag', 'tone' => $open ? 'danger' : 'ok'];
        }

        $answers = [];
        $section = function (SalesKnowledgeVersion $v, string $label) use (&$answers, $e, $who) {
            $text = Knowledge::toText($e->topic, $v->body, $v->data);
            if ($e->topic === 'terms' && ! empty($v->data['title'])) {
                $answers[] = ['q' => 'Heading', 'value' => (string) $v->data['title'], 'sub' => $label];
            }
            foreach (preg_split('/\R/', $text) ?: [] as $i => $line) {
                if (trim($line) !== '') {
                    $answers[] = ['q' => $i === 0 ? 'Content' : '', 'value' => $line, 'sub' => $label];
                }
            }
            if ($v->source) {
                $answers[] = ['q' => 'Source', 'value' => (string) $v->source, 'sub' => $label];
            }
            if ($v->change_note) {
                $answers[] = ['q' => 'Change note', 'value' => (string) $v->change_note, 'sub' => $label];
            }
            $answers[] = ['q' => 'Written by', 'value' => $who($v->created_by) . ($v->updated_by && $v->updated_by !== $v->created_by ? ' · last edited by ' . $who($v->updated_by) : ''), 'sub' => $label];
            if ($v->approved_by) {
                $answers[] = ['q' => 'Approved by', 'value' => $who($v->approved_by) . ' · ' . $v->approved_at?->format('j M Y, H:i'), 'sub' => $label];
            }
        };
        if ($live) {
            $section($live, 'Live — version ' . $live->version . ' (what customers, the coach and quotations use)');
        } else {
            $answers[] = ['q' => 'Live', 'value' => 'Nothing published yet: the bot, the coach and quotations do not use this entry.', 'sub' => 'Live', 'tone' => 'warn'];
        }
        if ($pending) {
            $label = 'Version ' . $pending->version . ' — ' . self::STATUS[$pending->status][0];
            foreach ($open as $i => $q) {
                $answers[] = ['q' => 'Question ' . ($i + 1), 'value' => (string) $q, 'sub' => $label, 'tone' => 'danger'];
            }
            foreach ((array) $pending->resolved_questions as $r) {
                $answers[] = ['q' => 'Answered', 'value' => $r['question'] . ' → ' . $r['answer'] . ' (' . ($r['by_name'] ?? 'Staff') . ')', 'sub' => $label, 'tone' => 'ok'];
            }
            $section($pending, $label);
        }

        $timeline = [];
        if ($manage) {
            $order = [];
            foreach ($versions as $v) {
                foreach ([['created_at', 'created_by', 'drafted'], ['approved_at', 'approved_by', 'approved'], ['published_at', 'published_by', 'published'],
                    ['retired_at', 'retired_by', 'retired'], ['rejected_at', 'rejected_by', 'rejected']] as $step => [$at, $by, $what]) {
                    if ($v->{$at}) {
                        $order[] = [$v->{$at}->getTimestamp(), (int) $v->version, $step];
                        $timeline[] = (new TimelineEvent(at: $v->{$at}->toIso8601String(), type: $what, title: 'Version ' . $v->version . ' ' . $what,
                            detail: (($what === 'rejected' ? (string) $v->reject_reason : ($what === 'drafted' ? (string) ($v->change_note ?? '') : '')) ?: null),
                            actor: $who($v->{$by})))->toArray();
                    }
                }
            }
            // Newest first; steps in the same second keep their real order (drafted < approved < published).
            array_multisort(array_column($order, 0), SORT_DESC, array_column($order, 1), SORT_DESC, array_column($order, 2), SORT_DESC, $timeline);
        }

        return (new WorkspacePayload)
            ->object(['type' => 'salesKnowledge', 'id' => (string) $e->id, 'title' => $e->title, 'subtitle' => $topic . ' · ' . $e->key, 'status' => $live ? 'live' : 'not_live'])
            ->headline($live ? 'Live' : 'Not live', 'status', $live ? 'ok' : 'warn')
            ->stats($stats)
            ->answers($answers)
            ->timeline($timeline)
            ->actions($manage ? $this->actions($e, $live, $pending, $open) : [])
            ->meta(['capability' => 'salesKnowledge', 'ttl' => 15, 'permissions' => []]);
    }

    private function actions(SalesKnowledgeEntry $e, ?SalesKnowledgeVersion $live, ?SalesKnowledgeVersion $pending, array $open): array
    {
        $base = '/api/v1/workspace/salesKnowledge/' . $e->id;
        $a = [];
        if ($pending && $pending->status === 'draft') {
            if ($open) {
                $a[] = ['key' => 'answer_question', 'label' => 'Answer a question', 'method' => 'POST', 'endpoint' => $base . '/answer_question', 'actor' => 'human',
                    'tone' => 'primary', 'submitLabel' => 'Save answer',
                    'schema' => ['question' => 'combo:' . implode('|', array_map(fn ($q, $i) => ($i + 1) . ' — ' . mb_substr(str_replace('|', '/', (string) $q), 0, 90), $open, array_keys($open))), 'answer' => 'textarea'],
                    'labels' => ['question' => 'Question', 'answer' => 'Management\'s answer (recorded). If the text must change, also edit the draft.'],
                    'prefill' => ['question' => '1 — ' . mb_substr(str_replace('|', '/', (string) $open[0]), 0, 90)]];
            } else {
                $a[] = ['key' => 'approve', 'label' => 'Approve', 'method' => 'POST', 'endpoint' => $base . '/approve', 'actor' => 'human', 'tone' => 'primary',
                    'confirm' => 'Approve version ' . $pending->version . '? Its text can no longer be changed. It goes live only when published.'];
            }
            $a[] = ['key' => 'edit_draft', 'label' => 'Edit draft', 'method' => 'POST', 'endpoint' => $base . '/edit_draft', 'actor' => 'human', 'submitLabel' => 'Save draft',
                'schema' => ['content' => 'textarea', 'note' => 'text'], 'labels' => ['content' => self::contentLabel($e->topic), 'note' => 'Change note (optional)'],
                'prefill' => ['content' => Knowledge::toText($e->topic, $pending->body, $pending->data), 'note' => (string) ($pending->change_note ?? '')]];
        }
        if ($pending && $pending->status === 'approved') {
            $a[] = ['key' => 'publish', 'label' => 'Publish', 'method' => 'POST', 'endpoint' => $base . '/publish', 'actor' => 'human', 'tone' => 'primary',
                'confirm' => 'Publish version ' . $pending->version . '? From now on the bot, the coach and quotations use it' . ($live ? ', and version ' . $live->version . ' is retired.' : '.')];
        }
        if ($pending) {
            $a[] = ['key' => 'reject', 'label' => 'Reject version ' . $pending->version, 'method' => 'POST', 'endpoint' => $base . '/reject', 'actor' => 'human', 'tone' => 'danger',
                'submitLabel' => 'Reject', 'schema' => ['reason' => 'text'], 'labels' => ['reason' => 'Why (recorded)']];
        } else {
            $a[] = ['key' => 'new_version', 'label' => $live ? 'Change (new version)' : 'Write a new version', 'method' => 'POST', 'endpoint' => $base . '/new_version', 'actor' => 'human',
                'tone' => 'primary', 'submitLabel' => 'Save as draft',
                'schema' => ['content' => 'textarea', 'note' => 'text'], 'labels' => ['content' => self::contentLabel($e->topic), 'note' => 'What changed and why'],
                'prefill' => ['content' => $live ? Knowledge::toText($e->topic, $live->body, $live->data) : '']];
        }
        if ($live) {
            $a[] = ['key' => 'retire', 'label' => 'Take offline', 'method' => 'POST', 'endpoint' => $base . '/retire', 'actor' => 'human', 'tone' => 'danger',
                'submitLabel' => 'Take offline', 'schema' => ['reason' => 'text'], 'labels' => ['reason' => 'Why (recorded). Nothing replaces it until a new version is published.']];
        }

        return $a;
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if (! SalesOwnership::enabled($ctx->tenant)) {
            return ['ok' => false, 'error' => 'not_available'];
        }
        $e = SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->find((int) ($input['id'] ?? 0));
        if (! $e) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $brain = app(SalesBrain::class);
        $vs = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $ctx->tenantId())->where('entry_id', $e->id);
        $pending = (clone $vs)->whereIn('status', ['draft', 'approved'])->orderByDesc('version')->first();
        $live = (clone $vs)->where('status', 'published')->first();
        $need = fn ($v) => $v ? null : ['ok' => false, 'error' => 'not_found', 'message' => 'There is no version at that step.'];

        $r = match ($action) {
            'edit_draft' => $need($pending) ?? $brain->editDraft($ctx->tenant, $ctx->user, $pending->id,
                ...$this->content($e, (string) ($input['content'] ?? '')), note: trim((string) ($input['note'] ?? '')) ?: null),
            'answer_question' => $need($pending) ?? $brain->resolveQuestion($ctx->tenant, $ctx->user, $pending->id,
                max(0, (int) preg_replace('/\D.*$/s', '', trim((string) ($input['question'] ?? ''))) - 1), (string) ($input['answer'] ?? '')),
            'approve' => $need($pending) ?? $brain->approve($ctx->tenant, $ctx->user, $pending->id),
            'publish' => $need($pending) ?? $brain->publish($ctx->tenant, $ctx->user, $pending->id),
            'reject'  => $need($pending) ?? $brain->reject($ctx->tenant, $ctx->user, $pending->id, (string) ($input['reason'] ?? '')),
            'retire'  => $need($live) ?? $brain->retire($ctx->tenant, $ctx->user, $live->id, (string) ($input['reason'] ?? '')),
            'new_version' => $brain->startVersion($ctx->tenant, $ctx->user, $e->topic, $e->key, $e->title,
                ...$this->content($e, (string) ($input['content'] ?? '')), note: trim((string) ($input['note'] ?? '')) ?: null),
            default => ['ok' => false, 'error' => 'unknown_action'],
        };
        if (! ($r['ok'] ?? false)) {
            return $r + ['message' => Messages::error($r)];
        }

        return ['ok' => true, 'message' => match ($action) {
            'edit_draft' => 'Draft saved.',
            'answer_question' => ($r['open'] ?? 0) ? 'Answer saved. ' . $r['open'] . ' question(s) still open.' : 'Answer saved. No questions left: the draft can now be approved.',
            'approve' => 'Approved. Publish it to make it live.',
            'publish' => 'Published. It is live now.',
            'reject' => 'Rejected.',
            'retire' => 'Taken offline.',
            'new_version' => 'Saved as draft version ' . $r['version'] . '.',
            default => 'Done.',
        }];
    }

    /** Text box → [body, data] for SalesBrain, keeping a terms entry's heading. */
    private function content(SalesKnowledgeEntry $e, string $text): array
    {
        [$body, $data] = Knowledge::fromText($e->topic, $text, $e->title, $e->key);
        if ($e->topic === 'terms') {
            $current = SalesKnowledgeVersion::withoutGlobalScopes()->where('entry_id', $e->id)->orderByDesc('version')->value('data');
            $heading = is_array($current) ? ($current['title'] ?? '') : (is_string($current) ? (json_decode($current, true)['title'] ?? '') : '');
            if ($heading !== '') {
                $data['title'] = $heading;
            }
        }

        return [$body, $data];
    }

    private static function contentLabel(string $topic): string
    {
        return match ($topic) {
            'terms'    => 'Terms, one per line: "Label: text"',
            'coverage' => 'Areas, one per line: "Name | starlink or fiber | covered, survey or none | place names, comma separated | note"',
            'quiz'     => 'Questions, separated by a blank line: "Q: question", then one option per line — "* " before the correct one, "- " before the others — and optionally "Why: explanation"',
            default    => 'Content',
        };
    }
}
