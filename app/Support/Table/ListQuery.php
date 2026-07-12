<?php declare(strict_types=1);

namespace App\Support\Table;

use Illuminate\Http\Request;

/**
 * The server list-request contract (Roadmap E8.1). Every register (Invoices, Payments, Orders, …)
 * accepts the SAME shape: a free-text search `q`, a map of `filter[field] => value`, a `sort` field
 * with `dir`, and `page`/`per_page`. Parsing lives here so all list endpoints behave identically.
 */
final class ListQuery
{
    public function __construct(
        public readonly string $q,
        /** @var array<string,mixed> */
        public readonly array $filters,
        public readonly ?string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public static function fromRequest(Request $r, int $defaultPerPage = 25, int $maxPerPage = 200): self
    {
        $filters = $r->query('filter', []);
        if (! is_array($filters)) $filters = [];
        // keep only scalar, non-empty filter values
        $filters = array_filter($filters, fn ($v) => (is_scalar($v) && trim((string) $v) !== '') || is_array($v));

        $dir = strtolower((string) $r->query('dir', 'asc'));
        if (! in_array($dir, ['asc', 'desc'], true)) $dir = 'asc';

        $per = (int) $r->query('per_page', $defaultPerPage);
        $per = max(1, min($maxPerPage, $per ?: $defaultPerPage));

        return new self(
            q: trim((string) $r->query('q', '')),
            filters: $filters,
            sort: ($s = trim((string) $r->query('sort', ''))) !== '' ? $s : null,
            dir: $dir,
            page: max(1, (int) $r->query('page', 1)),
            perPage: $per,
        );
    }
}
