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
    /** How many numbers the most recent phones() call dropped as excluded. */
    protected int $lastRemoved = 0;

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
                // v4.60 — discovery does not add anyone to an audience; a purchase does.
                // Filtered HERE rather than in norm(), because norm() also receives phones that came
                // from orders, and a business that has bought from you is a customer no matter what
                // its stage says the night before relationship:stages next runs.
                $fromProfiles = \App\Services\Crm\BusinessStage::scope(
                    CustomerProfile::whereNotNull('phone')
                )->pluck('phone');
                return $this->norm($fromOrders->concat($fromProfiles));
        }
    }

    public function count(string $audience, ?string $category = null): int
    {
        return count($this->phones($audience, $category));
    }

    /**
     * Normalise to digits, drop blanks, de-duplicate — and remove anyone the shop has
     * excluded.
     *
     * v4.32: the exclusion is applied HERE, in the one place every audience mode passes
     * through, rather than in each of the five branches above. Add a sixth audience mode
     * tomorrow and it is filtered without anyone remembering to do it.
     */
    protected function norm($collection): array
    {
        $phones = collect($collection)
            ->map(fn ($p) => preg_replace('/[^0-9]/', '', (string) $p))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $ex = \App\Services\Marketing\CampaignExclusions::for($this->tenant());
        if ($ex->isEmpty()) {
            $this->lastRemoved = 0;

            return $phones;
        }
        $res = $ex->filter($phones);
        $this->lastRemoved = count($res['removed']);

        return $res['kept'];
    }

    /** How many people the LAST phones() call actually removed — not the size of the list. */
    public function lastRemoved(): int
    {
        return $this->lastRemoved;
    }

    /** The tenant this resolver is running for — queries above are tenant-scoped by context. */
    protected function tenant(): ?\App\Models\Tenant
    {
        try {
            $id = app(\App\Support\TenantContext::class)->id();

            return $id ? \App\Models\Tenant::find($id) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
