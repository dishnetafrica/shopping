<?php declare(strict_types=1);

namespace App\Reports;

/**
 * One filter a report accepts (Roadmap E7.1). `type` is text | select | date; `options` lists the
 * allowed values for a select. Definitions declare their filters; the API (E7.2) validates input
 * against them before handing them to the source service.
 */
final class ReportFilter
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly array $options = [],
    ) {}

    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type, 'options' => $this->options];
    }
}
