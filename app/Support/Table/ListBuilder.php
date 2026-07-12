<?php declare(strict_types=1);

namespace App\Support\Table;

/**
 * Applies a ListQuery to an Eloquent/DB query per a declarative spec, returning a ListResult
 * (Roadmap E8.1). This is the ONE server-side list engine: search, whitelist-validated filters and
 * sort, aggregates over the full filtered set, then a single page of rows. Every register uses it, so
 * they page/filter/sort identically and none can be SQL-injected via arbitrary sort/filter fields.
 *
 * Spec keys:
 *   search      => ['col', ...]                 columns matched (LIKE %q%) by the free-text `q`
 *   filters     => ['field' => 'column', ...]   allowed filters → real columns (array value = whereIn)
 *   sortable    => ['field' => 'column', ...]    allowed sort fields → real columns (or a plain list)
 *   default_sort=> ['column' => 'id', 'dir' => 'desc']
 *   aggregates  => ['key' => ['fn' => 'sum', 'column' => 'total'], ...]  computed over the filtered set
 *   map         => fn ($row) => array            optional per-row transform
 */
class ListBuilder
{
    public static function apply($query, ListQuery $lq, array $spec): ListResult
    {
        // --- free-text search across whitelisted columns ---
        $search = $spec['search'] ?? [];
        if ($lq->q !== '' && $search) {
            $query->where(function ($w) use ($search, $lq) {
                foreach ($search as $col) {
                    $w->orWhere($col, 'like', '%' . $lq->q . '%');
                }
            });
        }

        // --- whitelist-validated filters ---
        $allowedFilters = $spec['filters'] ?? [];
        foreach ($lq->filters as $field => $value) {
            if (! array_key_exists($field, $allowedFilters)) continue; // ignore unknown fields
            $col = $allowedFilters[$field];
            if (is_array($value)) {
                $query->whereIn($col, $value);
            } else {
                $query->where($col, $value);
            }
        }

        // --- total + aggregates over the FILTERED set (before paging) ---
        $total = (clone $query)->count();

        $aggregates = [];
        foreach (($spec['aggregates'] ?? []) as $key => $agg) {
            $fn  = strtolower((string) ($agg['fn'] ?? 'sum'));
            $col = (string) ($agg['column'] ?? '');
            if ($col === '') continue;
            $aggregates[$key] = match ($fn) {
                'count' => (int) (clone $query)->count(),
                'avg'   => (float) (clone $query)->avg($col),
                'min'   => (float) (clone $query)->min($col),
                'max'   => (float) (clone $query)->max($col),
                default => (float) (clone $query)->sum($col),
            };
        }

        // --- sort (whitelist), then default ---
        [$sortCol, $sortDir] = self::resolveSort($lq, $spec);
        if ($sortCol !== null) $query->orderBy($sortCol, $sortDir);

        // --- one page of rows ---
        $rows = $query->forPage($lq->page, $lq->perPage)->get();
        if (isset($spec['map']) && is_callable($spec['map'])) {
            $rows = $rows->map($spec['map']);
        }
        $rows = method_exists($rows, 'values') ? $rows->values()->all() : (array) $rows;

        return new ListResult($rows, $total, $lq->page, $lq->perPage, $aggregates);
    }

    /**
     * The FULL filtered+sorted set (no pagination), for export (Roadmap E8.2). Applies the same
     * search/filter/sort/map as apply() but returns every matching row up to a safety cap.
     *
     * @return array<int,mixed>
     */
    public static function allRows($query, ListQuery $lq, array $spec, int $cap = 10000): array
    {
        $search = $spec['search'] ?? [];
        if ($lq->q !== '' && $search) {
            $query->where(function ($w) use ($search, $lq) {
                foreach ($search as $col) $w->orWhere($col, 'like', '%' . $lq->q . '%');
            });
        }
        $allowedFilters = $spec['filters'] ?? [];
        foreach ($lq->filters as $field => $value) {
            if (! array_key_exists($field, $allowedFilters)) continue;
            $col = $allowedFilters[$field];
            is_array($value) ? $query->whereIn($col, $value) : $query->where($col, $value);
        }
        [$sortCol, $sortDir] = self::resolveSort($lq, $spec);
        if ($sortCol !== null) $query->orderBy($sortCol, $sortDir);

        $rows = $query->limit($cap)->get();
        if (isset($spec['map']) && is_callable($spec['map'])) {
            $rows = $rows->map($spec['map']);
        }
        return method_exists($rows, 'values') ? $rows->values()->all() : (array) $rows;
    }

    /** @return array{0:?string,1:string} [column|null, dir] */
    private static function resolveSort(ListQuery $lq, array $spec): array
    {
        $sortable = $spec['sortable'] ?? [];
        // normalise a plain list ['a','b'] into ['a'=>'a','b'=>'b']
        $map = [];
        foreach ($sortable as $k => $v) {
            if (is_int($k)) $map[$v] = $v; else $map[$k] = $v;
        }

        if ($lq->sort !== null && array_key_exists($lq->sort, $map)) {
            return [$map[$lq->sort], $lq->dir];
        }

        $default = $spec['default_sort'] ?? null;
        if (is_array($default) && isset($default['column'])) {
            return [(string) $default['column'], strtolower((string) ($default['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc'];
        }
        return [null, $lq->dir];
    }
}
