<?php

namespace App\Capability\Dto;

/**
 * The permanent envelope every workspace returns and every frontend consumes.
 * Additive only within a major version. Knows nothing about models or tables.
 */
final class WorkspacePayload
{
    private array $object = [];
    private ?array $headline = null;
    private array $financials = [];
    private ?array $brief = null;
    private array $timeline = [];
    private array $documents = [];
    private array $related = [];
    private array $actions = [];
    private ?array $table = null;
    // --- record-shell extensions (all optional; a plain 360 leaves them null/empty) ---
    private array $answers = [];
    private ?array $health = null;
    private array $stats = [];
    private array $tabs = [];
    private array $overviewCards = [];
    private array $tabSections = [];
    private ?array $statement = null;
    private array $pipeline = [];
    private array $financialsCards = [];
    private ?array $nba = null;
    private array $quickActions = [];
    private array $more = [];
    private array $meta = [];

    public function object(array $o): self { $this->object = $o; return $this; }

    public function headline(string $value, string $label, string $tone = 'default'): self
    {
        $this->headline = ['value' => $value, 'label' => $label, 'tone' => $tone];
        return $this;
    }

    /** @param array<Financial|array> $f */
    public function financials(array $f): self
    {
        $this->financials = array_map(fn ($x) => $x instanceof Financial ? $x->toArray() : $x, $f);
        return $this;
    }

    public function brief(?Brief $b): self { $this->brief = $b?->toArray(); return $this; }

    /** @param array<TimelineEvent|array> $t */
    public function timeline(array $t): self
    {
        $this->timeline = array_map(fn ($x) => $x instanceof TimelineEvent ? $x->toArray() : $x, $t);
        return $this;
    }

    public function documents(array $d): self { $this->documents = $d; return $this; }

    /** @param array<Related|array> $r */
    public function related(array $r): self
    {
        $this->related = array_map(fn ($x) => $x instanceof Related ? $x->toArray() : $x, $r);
        return $this;
    }

    /** @param array<ActionSpec|array> $a */
    public function actions(array $a): self
    {
        $this->actions = array_map(fn ($x) => $x instanceof ActionSpec ? $x->toArray() : $x, $a);
        return $this;
    }

    /**
     * A directory/list section: sortable, filterable rows with typed columns. Additive to the
     * envelope (a workspace that lists many records rather than projecting one). Each row is a map
     * of column-key => cell, where a cell may be a scalar or ['value'=>, 'tone'=>, 'sub'=>, 'link'=>].
     *
     * @param array<int,array{key:string,label:string,align?:string,type?:string,sortable?:bool}> $columns
     * @param array<int,array<string,mixed>> $rows
     */
    public function table(array $columns, array $rows, array $filters = [], array $opts = []): self
    {
        // $opts may carry: dateKey, dateLabel (enables a From/To range filter on that column),
        // totals [['key','label','money'?]] (Grid Totals footer), pageSize, currency.
        $this->table = array_merge(['columns' => $columns, 'rows' => $rows, 'filters' => $filters], $opts);
        return $this;
    }

    /**
     * The signature "answers" grid: quick, plain-language answers about this record.
     * @param array<int,array{q:string,value:string,tone?:string,sub?:string}> $a
     */
    public function answers(array $a): self { $this->answers = $a; return $this; }

    /**
     * A health scorecard: a 0–100 ring plus supporting rows.
     * @param array{score:int,title?:string,meta?:array<int,array{label:string,value:string,tone?:string}>} $h
     */
    public function health(array $h): self { $this->health = $h; return $this; }

    /** KPI cards shown at the top of the Overview tab. @param array<int,array<string,mixed>> $s */
    public function stats(array $s): self { $this->stats = $s; return $this; }

    /** Ordered tab bar for the record shell. @param array<int,array{key:string,label:string,count?:int}> $t */
    public function tabs(array $t): self { $this->tabs = $t; return $this; }

    /** Card specs rendered under the Overview tab (kind: dl | table | links). @param array<int,array<string,mixed>> $c */
    public function overviewCards(array $c): self { $this->overviewCards = $c; return $this; }

    /**
     * Extra record tabs beyond overview/timeline/financials/documents: a map of tab-key => card specs
     * (same kinds as overviewCards). The tab must also be listed in tabs() to appear. @param array<string,array> $s
     */
    public function tabSections(array $s): self { $this->tabSections = $s; return $this; }

    /**
     * Statement of account for the Financials tab.
     * @param array<int,array<string,mixed>> $rows
     */
    public function statement(array $rows, string $balanceLabel, string $balanceValue): self
    {
        $this->statement = ['rows' => $rows, 'balanceLabel' => $balanceLabel, 'balanceValue' => $balanceValue];
        return $this;
    }

    /**
     * A horizontal stage tracker (e.g. Order → Confirmed → Delivered → Invoiced → Paid).
     * @param array<int,array{label:string,value?:string,state:string,icon?:string}> $stages
     */
    public function pipeline(array $stages): self { $this->pipeline = $stages; return $this; }

    /** Extra card specs for the Financials tab (same kinds as overviewCards). @param array<int,array<string,mixed>> $c */
    public function financialsCards(array $c): self { $this->financialsCards = $c; return $this; }

    /** The single highlighted next-best-action in the rail. */
    public function nba(string $text, array $action): self { $this->nba = ['text' => $text, 'action' => $action]; return $this; }

    /** @param array<int,array<string,mixed>> $qa */
    public function quickActions(array $qa): self
    {
        $this->quickActions = array_map(fn ($x) => $x instanceof ActionSpec ? $x->toArray() : $x, $qa);
        return $this;
    }

    /** Overflow ("…") menu actions in the record header. @param array<int,array<string,mixed>> $m */
    public function more(array $m): self
    {
        $this->more = array_map(fn ($x) => $x instanceof ActionSpec ? $x->toArray() : $x, $m);
        return $this;
    }

    public function meta(array $m): self
    {
        $this->meta = array_merge(['version' => '1.0'], $this->meta, $m);
        return $this;
    }

    public function toArray(): array
    {
        return [
            'object' => $this->object,
            'headline' => $this->headline,
            'financials' => $this->financials,
            'brief' => $this->brief,
            'timeline' => $this->timeline,
            'documents' => $this->documents,
            'related' => $this->related,
            'actions' => $this->actions,
            'table' => $this->table,
            'answers' => $this->answers,
            'health' => $this->health,
            'stats' => $this->stats,
            'tabs' => $this->tabs,
            'overviewCards' => $this->overviewCards,
            'tabSections' => $this->tabSections,
            'statement' => $this->statement,
            'pipeline' => $this->pipeline,
            'financialsCards' => $this->financialsCards,
            'nba' => $this->nba,
            'quickActions' => $this->quickActions,
            'more' => $this->more,
            'meta' => $this->meta,
        ];
    }
}
