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
    public function table(array $columns, array $rows): self
    {
        $this->table = ['columns' => $columns, 'rows' => $rows];
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
            'meta' => $this->meta,
        ];
    }
}
