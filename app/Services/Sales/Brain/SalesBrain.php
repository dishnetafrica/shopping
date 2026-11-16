<?php

namespace App\Services\Sales\Brain;

use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Sales\SalesOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Sales Brain — the ONE writer of sales knowledge and the ONE reader of what is published.
 *
 *   Writing (owner/admin or manager only): start a version, edit a draft, settle an open question,
 *   approve, publish, reject, retire. Every step is stamped on the version (who, when) and written to
 *   the audit log. Approved content is never edited; a change is a new version.
 *
 *   Reading: published() / quoteTerms() / coverage() return PUBLISHED versions only. These are the
 *   only ways the bot, the salesperson coach and the quotation get sales knowledge, so a draft,
 *   an approved-but-unpublished or a rejected version can never reach a customer.
 *
 * Rules are in Knowledge (pure). The business is the market: DishNetSS knowledge is South Sudan only.
 */
class SalesBrain
{
    private static ?bool $ready = null;

    /** The tables exist (the migration has run). Reads return nothing until then. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('sales_knowledge_versions');
    }

    // ── writing ──────────────────────────────────────────────────────────

    /**
     * Start a new draft version of an entry (creating the entry if it is new).
     * $by null is allowed only from the command line (the template import), recorded as $system.
     */
    public function startVersion(Tenant $tenant, ?User $by, string $topic, string $key, string $title, ?string $body, $data = null,
        ?string $source = null, array $questions = [], ?string $note = null, string $system = 'system'): array
    {
        if ($deny = $this->denied($tenant, $by, true)) {
            return $deny;
        }
        $key = Knowledge::key($key);
        $title = trim($title);
        if ($key === '' || $title === '') {
            return ['ok' => false, 'error' => 'title_and_key_required'];
        }
        $v = Knowledge::validate($topic, $body, $data);
        if (! $v['ok']) {
            return $v;
        }
        $questions = $this->cleanQuestions($questions);

        return DB::transaction(function () use ($tenant, $by, $topic, $key, $title, $v, $source, $questions, $note, $system) {
            $entry = SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('topic', $topic)->where('key', $key)->lockForUpdate()->first()
                ?? SalesKnowledgeEntry::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'topic' => $topic, 'key' => $key, 'title' => mb_substr($title, 0, 160)]);

            $existing = SalesKnowledgeVersion::withoutGlobalScopes()->where('entry_id', $entry->id)->lockForUpdate()->get(['version', 'status']);
            if (! Knowledge::canStartVersion($existing->pluck('status')->all())) {
                return ['ok' => false, 'error' => 'version_in_progress', 'message' => 'This entry already has a draft or an approved version waiting. Finish or reject it first.'];
            }
            if ($entry->title !== $title) {
                $entry->update(['title' => mb_substr($title, 0, 160)]);
            }
            $ver = SalesKnowledgeVersion::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'entry_id' => $entry->id, 'version' => ((int) $existing->max('version')) + 1, 'status' => 'draft',
                'body' => $v['body'], 'data' => $v['data'], 'source' => $source ? mb_substr($source, 0, 200) : null,
                'open_questions' => $questions ?: null, 'change_note' => $note ? mb_substr(trim($note), 0, 500) : null,
                'created_by' => $by?->id, 'updated_by' => $by?->id,
            ]);
            AuditLogger::log($tenant->id, 'sales_knowledge', $ver->id, 'draft', $by ?? $system, null,
                ['entry' => "{$topic}/{$key}", 'version' => $ver->version, 'source' => $source, 'open_questions' => count($questions)], $note);

            return ['ok' => true, 'entry_id' => $entry->id, 'version_id' => $ver->id, 'version' => $ver->version];
        });
    }

    /** Change a draft's content (only a draft can change). */
    public function editDraft(Tenant $tenant, User $by, int $versionId, ?string $body, $data = null, ?string $note = null): array
    {
        return $this->step($tenant, $by, $versionId, 'edit', function (SalesKnowledgeVersion $ver, SalesKnowledgeEntry $entry) use ($body, $data, $note, $by) {
            if (! Knowledge::editable($ver->status)) {
                return ['ok' => false, 'error' => 'not_a_draft', 'message' => 'Only a draft can be changed. Start a new version instead.'];
            }
            $v = Knowledge::validate($entry->topic, $body, $data);
            if (! $v['ok']) {
                return $v;
            }
            $old = ['body' => $ver->body, 'data' => $ver->data];
            $ver->update(['body' => $v['body'], 'data' => $v['data'], 'updated_by' => $by->id,
                'change_note' => $note !== null ? mb_substr(trim($note), 0, 500) : $ver->change_note]);

            return ['ok' => true, '_audit' => [$old, ['body' => $v['body'], 'data' => $v['data']], $note]];
        });
    }

    /** Settle one of a draft's open questions; the answer is kept on the version. */
    public function resolveQuestion(Tenant $tenant, User $by, int $versionId, int $index, string $answer): array
    {
        $answer = trim($answer);
        if ($answer === '') {
            return ['ok' => false, 'error' => 'answer_required'];
        }

        return $this->step($tenant, $by, $versionId, 'resolve_question', function (SalesKnowledgeVersion $ver) use ($index, $answer, $by) {
            if (! Knowledge::editable($ver->status)) {
                return ['ok' => false, 'error' => 'not_a_draft'];
            }
            $open = array_values((array) $ver->open_questions);
            if (! isset($open[$index])) {
                return ['ok' => false, 'error' => 'unknown_question'];
            }
            $q = $open[$index];
            array_splice($open, $index, 1);
            $done = array_values((array) $ver->resolved_questions);
            $done[] = ['question' => $q, 'answer' => mb_substr($answer, 0, 1000), 'by' => $by->id, 'by_name' => $by->name, 'at' => now()->toIso8601String()];
            $ver->update(['open_questions' => $open ?: null, 'resolved_questions' => $done, 'updated_by' => $by->id]);

            return ['ok' => true, 'open' => count($open), '_audit' => [['question' => $q], ['answer' => $answer], null]];
        });
    }

    public function approve(Tenant $tenant, User $by, int $versionId): array
    {
        return $this->step($tenant, $by, $versionId, 'approve', function (SalesKnowledgeVersion $ver) use ($by) {
            if ($ver->status === 'draft' && ! empty($ver->open_questions)) {
                return ['ok' => false, 'error' => 'open_questions', 'message' => 'Settle every open question before approving.', 'open' => count($ver->open_questions)];
            }
            if ($ver->status === 'draft' && Knowledge::hasPlaceholder($ver->body, $ver->data)) {
                return ['ok' => false, 'error' => 'unanswered', 'message' => 'Some questions still say [TO ANSWER]. Write those answers (or delete the questions) first.'];
            }
            if (! ($to = Knowledge::next($ver->status, 'approve'))) {
                return ['ok' => false, 'error' => 'not_allowed', 'message' => "A {$ver->status} version cannot be approved."];
            }
            $ver->update(['status' => $to, 'approved_by' => $by->id, 'approved_at' => now()]);

            return ['ok' => true];
        });
    }

    /** Publish an approved version. The version it replaces (if any) is retired in the same step. */
    public function publish(Tenant $tenant, User $by, int $versionId): array
    {
        return $this->step($tenant, $by, $versionId, 'publish', function (SalesKnowledgeVersion $ver) use ($by) {
            if (! ($to = Knowledge::next($ver->status, 'publish'))) {
                return ['ok' => false, 'error' => 'not_allowed', 'message' => "Only an approved version can be published (this one is {$ver->status})."];
            }
            $replaced = SalesKnowledgeVersion::withoutGlobalScopes()->where('entry_id', $ver->entry_id)->where('status', 'published')->lockForUpdate()->get();
            foreach ($replaced as $old) {
                $old->update(['status' => 'retired', 'retired_by' => $by->id, 'retired_at' => now()]);
            }
            $ver->update(['status' => $to, 'published_by' => $by->id, 'published_at' => now()]);

            return ['ok' => true, '_audit' => [['replaced_versions' => $replaced->pluck('version')->all()], ['version' => $ver->version], null]];
        });
    }

    public function reject(Tenant $tenant, User $by, int $versionId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        return $this->step($tenant, $by, $versionId, 'reject', function (SalesKnowledgeVersion $ver) use ($by, $reason) {
            if (! ($to = Knowledge::next($ver->status, 'reject'))) {
                return ['ok' => false, 'error' => 'not_allowed'];
            }
            $ver->update(['status' => $to, 'rejected_by' => $by->id, 'rejected_at' => now(), 'reject_reason' => mb_substr($reason, 0, 500)]);

            return ['ok' => true, '_audit' => [null, null, $reason]];
        });
    }

    /** Take a published version out of use without a replacement. */
    public function retire(Tenant $tenant, User $by, int $versionId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        return $this->step($tenant, $by, $versionId, 'retire', function (SalesKnowledgeVersion $ver) use ($by, $reason) {
            if (! ($to = Knowledge::next($ver->status, 'retire'))) {
                return ['ok' => false, 'error' => 'not_allowed'];
            }
            $ver->update(['status' => $to, 'retired_by' => $by->id, 'retired_at' => now()]);

            return ['ok' => true, '_audit' => [null, null, $reason]];
        });
    }

    // ── reading (published only) ─────────────────────────────────────────

    /**
     * Published knowledge of this business, optionally one topic.
     * @return list<array{topic:string,key:string,title:string,body:string,data:?array,version:int,published_at:?string}>
     */
    public function published(Tenant $tenant, ?string $topic = null): array
    {
        if (! self::ready()) {
            return [];
        }
        $q = SalesKnowledgeVersion::withoutGlobalScopes()
            ->join('sales_knowledge_entries as e', 'e.id', '=', 'sales_knowledge_versions.entry_id')
            ->where('sales_knowledge_versions.tenant_id', $tenant->id)->where('e.tenant_id', $tenant->id)
            ->where('sales_knowledge_versions.status', 'published')
            ->orderBy('e.topic')->orderBy('e.key');
        if ($topic !== null) {
            $q->where('e.topic', $topic);
        }

        return $q->get(['e.topic', 'e.key', 'e.title', 'sales_knowledge_versions.body', 'sales_knowledge_versions.data',
            'sales_knowledge_versions.version', 'sales_knowledge_versions.published_at'])
            ->map(fn ($r) => ['topic' => $r->topic, 'key' => $r->key, 'title' => $r->title, 'body' => (string) $r->body,
                'data' => $r->data, 'version' => (int) $r->version, 'published_at' => $r->published_at?->toIso8601String()])->all();
    }

    /** Terms to print on an Internet-provider quotation. Never stops a quotation: any failure → none. */
    public function quoteTerms(Tenant $tenant, array $serviceLines): array
    {
        try {
            $byKey = [];
            foreach ($this->published($tenant, 'terms') as $p) {
                $byKey[$p['key']] = $p['data'];
            }

            return Knowledge::quoteTerms($serviceLines, $byKey);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Coverage answer from PUBLISHED areas only. Unknown place → "survey". */
    public function coverage(Tenant $tenant, string $serviceLine, string $placeText, ?float $lat = null, ?float $lng = null): array
    {
        $areas = [];
        foreach ($this->published($tenant, 'coverage') as $p) {
            foreach ((array) ($p['data']['areas'] ?? []) as $a) {
                $areas[] = $a;
            }
        }

        return CoverageCheck::check($areas, $serviceLine, $placeText, $lat, $lng);
    }

    // ── internals ────────────────────────────────────────────────────────

    /** Run one step on a version under a row lock, stamp the audit log, strip internal keys. */
    private function step(Tenant $tenant, ?User $by, int $versionId, string $action, callable $fn): array
    {
        if ($deny = $this->denied($tenant, $by, false)) {
            return $deny;
        }

        return DB::transaction(function () use ($tenant, $by, $versionId, $action, $fn) {
            $ver = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->find($versionId);
            if (! $ver) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            $entry = SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($ver->entry_id);
            $from = $ver->status;
            $r = $fn($ver, $entry);
            if ($r['ok'] ?? false) {
                [$old, $new, $reason] = $r['_audit'] ?? [null, null, null];
                AuditLogger::log($tenant->id, 'sales_knowledge', $ver->id, $action, $by,
                    array_merge(['entry' => "{$entry->topic}/{$entry->key}", 'version' => $ver->version, 'status' => $from], (array) $old),
                    array_merge(['status' => $ver->status], (array) $new), $reason);
            }
            unset($r['_audit']);

            return $r;
        });
    }

    private function denied(Tenant $tenant, ?User $by, bool $allowSystem): ?array
    {
        if ($by === null) {
            return ($allowSystem && app()->runningInConsole()) ? null : ['ok' => false, 'error' => 'forbidden'];
        }
        if ((int) $by->tenant_id !== (int) $tenant->id || ! SalesOwnership::canManage($by)) {
            return ['ok' => false, 'error' => 'forbidden', 'message' => 'Only the owner or a manager can change sales knowledge.'];
        }

        return null;
    }

    private function cleanQuestions(array $questions): array
    {
        return array_values(array_filter(array_map(fn ($q) => mb_substr(trim((string) $q), 0, 500), $questions), fn ($q) => $q !== ''));
    }
}
