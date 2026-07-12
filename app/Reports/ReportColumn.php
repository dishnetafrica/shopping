<?php declare(strict_types=1);

namespace App\Reports;

/**
 * One column of a report (Roadmap E7.1). `type` drives client-side formatting: text | number |
 * money | date. Value objects like this keep report definitions declarative and serialisable.
 */
final class ReportColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
    ) {}

    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
