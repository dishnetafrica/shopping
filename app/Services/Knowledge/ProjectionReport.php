<?php
namespace App\Services\Knowledge;

/** Outcome of a projection pass. Pure value object so the coordinator stays DB-free and testable. */
class ProjectionReport
{
    /** @var array<int,array{action:object,label:string}> */ public array $applied = [];
    /** @var array<int,array{action:object,error:string}> */ public array $failed = [];
    /** @var object[] */ public array $skipped = [];

    public function ok(object $action, string $label): void { $this->applied[] = ['action' => $action, 'label' => $label]; }
    public function fail(object $action, string $error): void { $this->failed[] = ['action' => $action, 'error' => $error]; }
    public function skip(object $action): void { $this->skipped[] = $action; }

    public function allOk(): bool { return $this->failed === []; }
    /** @return string[] */ public function labels(): array { return array_map(fn ($a) => $a['label'], $this->applied); }
}
