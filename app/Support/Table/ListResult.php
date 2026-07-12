<?php declare(strict_types=1);

namespace App\Support\Table;

/**
 * The server list-response contract (Roadmap E8.1): rows for the current page plus the paging math
 * and any aggregates computed over the FULL filtered set (not just the page). Every list endpoint
 * returns this shape, so one JS DataTable (E8.3) can render them all.
 */
final class ListResult
{
    public function __construct(
        /** @var array<int,mixed> */
        public readonly array $rows,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        /** @var array<string,mixed> */
        public readonly array $aggregates = [],
    ) {}

    public function pages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }

    public function toArray(): array
    {
        return [
            'rows'       => $this->rows,
            'total'      => $this->total,
            'page'       => $this->page,
            'per_page'   => $this->perPage,
            'pages'      => $this->pages(),
            'aggregates' => (object) $this->aggregates,
        ];
    }
}
