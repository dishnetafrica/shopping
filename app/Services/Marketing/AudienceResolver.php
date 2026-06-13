<?php
namespace App\Services\Marketing;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Product;

/**
 * Resolves a campaign audience to a list of normalised WhatsApp numbers.
 * Runs inside the active tenant context, so every query is tenant-scoped.
 */
class AudienceResolver
{
    /** @return string[] distinct digit-only phone numbers */
    public function phones(string $audience, ?string $category = null): array
    {
        $now = now();

        switch ($audience) {
            case 'recent': // ordered in the last 30 days
                return $this->norm(
                    Order::whereNotNull('customer_phone')
                        ->where('created_at', '>=', $now->copy()->subDays(30))
                        ->pluck('customer_phone')
                );

            case 'inactive': // ordered before, but not in the last 60 days
                $recent = $this->norm(
                    Order::whereNotNull('customer_phone')
                        ->where('created_at', '>=', $now->copy()->subDays(60))
                        ->pluck('customer_phone')
                );
                $all = $this->norm(Order::whereNotNull('customer_phone')->pluck('customer_phone'));
                return array_values(array_diff($all, $recent));

            case 'vip': // biggest spenders
                $rows = Order::whereNotNull('customer_phone')
                    ->selectRaw('customer_phone, SUM(total) as spent')
                    ->groupBy('customer_phone')
                    ->orderByDesc('spent')
                    ->limit(50)
                    ->pluck('customer_phone');
                return $this->norm($rows);

            case 'category': // customers who bought something in this category (best-effort text match)
                $names = Product::where('category', $category)->pluck('name')->all();
                if (! $names) return [];
                $q = Order::whereNotNull('customer_phone')->where(function ($w) use ($names) {
                    foreach ($names as $n) {
                        $w->orWhere('items_text', 'like', '%' . $n . '%');
                    }
                });
                return $this->norm($q->pluck('customer_phone'));

            case 'all':
            default:
                $fromOrders   = Order::whereNotNull('customer_phone')->pluck('customer_phone');
                $fromProfiles = CustomerProfile::whereNotNull('phone')->pluck('phone');
                return $this->norm($fromOrders->concat($fromProfiles));
        }
    }

    public function count(string $audience, ?string $category = null): int
    {
        return count($this->phones($audience, $category));
    }

    /** Normalise to digits, drop blanks, de-duplicate. */
    protected function norm($collection): array
    {
        return collect($collection)
            ->map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
