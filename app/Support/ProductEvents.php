<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Lightweight, never-throwing event logging that powers the analytics dashboard. */
class ProductEvents
{
    public static function log(int $tenantId, ?int $productId, string $event): void
    {
        if ($tenantId <= 0) return;
        try {
            DB::table('product_events')->insert([
                'tenant_id'  => $tenantId,
                'product_id' => $productId ?: null,
                'event'      => $event,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}
    }

    /** Bulk log the same event for several products (e.g. a category browse = many views). */
    public static function logMany(int $tenantId, array $productIds, string $event): void
    {
        if ($tenantId <= 0) return;
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($x) => $x > 0)));
        if (! $ids) return;
        $now  = now();
        $rows = array_map(fn ($id) => [
            'tenant_id' => $tenantId, 'product_id' => $id, 'event' => $event, 'created_at' => $now,
        ], $ids);
        try { DB::table('product_events')->insert($rows); } catch (\Throwable $e) {}
    }
}
