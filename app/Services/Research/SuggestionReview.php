<?php

namespace App\Services\Research;

use App\Models\Product;
use App\Models\ProductSuggestion;
use Illuminate\Support\Facades\Schema;

/**
 * Approving and rejecting researched suggestions.
 *
 * One place, because there are three callers — the single-item buttons in the legacy
 * panel, the v3 review screen, and its bulk actions — and the safety check must be
 * identical in all of them.
 *
 * THE CHECK: a suggestion is only ever written into a field that is STILL empty.
 * A row may have been raised days ago; someone may have typed the answer in since.
 * Approving must never overwrite that, and in bulk it matters far more, because
 * nobody is reading 800 rows carefully enough to notice.
 */
final class SuggestionReview
{
    /** Applying one row. Returns why it did not apply rather than failing silently. */
    public static function applyOne(ProductSuggestion $s, int $userId): array
    {
        $field = (string) $s->field;

        if (! in_array($field, (array) config('research.fields', []), true)
            || ! Schema::hasColumn('products', $field)) {
            return ['ok' => false, 'reason' => 'not_writable'];
        }

        $p = Product::find($s->product_id);
        if (! $p) {
            self::close($s, $userId, 'dismissed');

            return ['ok' => false, 'reason' => 'product_gone'];
        }

        if (trim((string) ($p->{$field} ?? '')) !== '') {
            // Someone answered it in the meantime — drop the suggestion, keep their work.
            self::close($s, $userId, 'dismissed');

            return ['ok' => false, 'reason' => 'already_filled'];
        }

        $p->{$field} = (string) $s->suggested_value;
        $p->save();
        self::close($s, $userId, 'applied');

        return ['ok' => true, 'reason' => 'applied'];
    }

    public static function dismissOne(ProductSuggestion $s, int $userId): void
    {
        self::close($s, $userId, 'dismissed');
    }

    /**
     * @param  iterable<ProductSuggestion>  $rows
     * @return array{applied:int,skipped:int,reasons:array<string,int>}
     */
    public static function applyMany(iterable $rows, int $userId): array
    {
        $applied = 0; $skipped = 0; $reasons = [];
        foreach ($rows as $s) {
            $r = self::applyOne($s, $userId);
            if ($r['ok']) {
                $applied++;
            } else {
                $skipped++;
                $reasons[$r['reason']] = ($reasons[$r['reason']] ?? 0) + 1;
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'reasons' => $reasons];
    }

    /** @param iterable<ProductSuggestion> $rows */
    public static function dismissMany(iterable $rows, int $userId): int
    {
        $n = 0;
        foreach ($rows as $s) {
            self::close($s, $userId, 'dismissed');
            $n++;
        }

        return $n;
    }

    /** Counts for the review screen's filter chips — how many are waiting, and of what. */
    public static function summary(int $tenantId): array
    {
        if (! Schema::hasTable('product_suggestions')) {
            return ['total' => 0, 'by_field' => [], 'by_source' => [], 'products' => 0];
        }
        $base = fn () => ProductSuggestion::where('tenant_id', $tenantId)->pending();

        return [
            'total'     => (clone $base())->count(),
            'products'  => (clone $base())->distinct('product_id')->count('product_id'),
            'by_field'  => (clone $base())->selectRaw('field, count(*) c')->groupBy('field')
                ->pluck('c', 'field')->all(),
            'by_source' => (clone $base())->selectRaw('source, count(*) c')->groupBy('source')
                ->pluck('c', 'source')->all(),
        ];
    }

    private static function close(ProductSuggestion $s, int $userId, string $status): void
    {
        $s->status = $status;
        $s->decided_by = $userId ?: null;
        $s->decided_at = now();
        $s->save();
    }
}
