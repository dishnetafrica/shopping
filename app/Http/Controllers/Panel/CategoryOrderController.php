<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

/**
 * One-shot reseed of `categories.sort` so the storefront department list follows an explicit
 * (food-first) order instead of raw product count. Additive & idempotent: it only UPDATES the
 * sort column of existing categories for the authenticated tenant — it never creates, renames or
 * deletes a category, and it never touches products. Re-running it just re-applies the same order.
 *
 * The storefront reads this order via the catalogue feed's `category_order` (see
 * StorefrontController::catalogue) and falls back to product-count sort when it's empty, so nothing
 * breaks for tenants that never call this.
 *
 * POST /papi/category-reseed
 *   body (optional): { "order": ["Whole Spices", "Ground Spices (Powders)", ...] }
 * With no body it applies the canonical Family Shoppers food-first order below. Names are matched
 * case-insensitively and trimmed. Any category NOT named in the list keeps its relative order but is
 * pushed below the listed ones (so non-food lines settle at the bottom).
 */
class CategoryOrderController extends Controller
{
    /** Canonical food-first department order (matches products-enriched.csv row order). */
    private const DEFAULT_ORDER = [
        'Whole Spices',
        'Ground Spices (Powders)',
        'Recipe Mixes & Masalas',
        'Dal & Pulses',
        'Grains, Flours & Staples',
        'Snacks & Namkeen',
        'Pickles, Chutneys & Pastes',
        'Sauces & Condiments',
        'Rice, Biryani & Ready Mixes',
        'Disposables, Foil & Wraps',
        'Cleaning & Hygiene',
        'Non-Food & General',
        'Flooring & Paint (Industrial)',
    ];

    public function reseed(Request $r)
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        }
        app(\App\Support\TenantContext::class)->set($t->id);

        // Requested order (or the canonical food-first default). Normalise to trimmed strings.
        $order = $r->input('order');
        if (! is_array($order) || empty($order)) {
            $order = self::DEFAULT_ORDER;
        }
        $order = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            $order
        ), fn ($n) => $n !== ''));

        // rank by lowercased name -> position
        $rank = [];
        foreach ($order as $i => $name) {
            $key = mb_strtolower($name);
            if (! isset($rank[$key])) {
                $rank[$key] = $i;
            }
        }

        $cats = Category::where('tenant_id', $t->id)->get();
        $listed = [];
        $unlisted = [];
        foreach ($cats as $c) {
            $key = mb_strtolower(trim((string) $c->name));
            if (isset($rank[$key])) {
                $listed[] = [$rank[$key], $c];
            } else {
                $unlisted[] = $c;
            }
        }

        // Listed first, in the requested order; unlisted after, keeping current sort then name.
        usort($listed, fn ($a, $b) => $a[0] <=> $b[0]);
        usort($unlisted, function ($a, $b) {
            $sa = (int) ($a->sort ?? 0);
            $sb = (int) ($b->sort ?? 0);
            return $sa === $sb
                ? strcasecmp((string) $a->name, (string) $b->name)
                : $sa <=> $sb;
        });

        $applied = [];
        $sort = 0;
        foreach ($listed as [$_, $c]) {
            if ((int) $c->sort !== $sort) {
                $c->sort = $sort;
                $c->save();
            }
            $applied[] = ['name' => $c->name, 'sort' => $sort];
            $sort++;
        }
        $missing = [];
        // Report any requested names that had no matching category (typo / not imported yet).
        $have = [];
        foreach ($cats as $c) {
            $have[mb_strtolower(trim((string) $c->name))] = true;
        }
        foreach ($order as $name) {
            if (! isset($have[mb_strtolower($name)])) {
                $missing[] = $name;
            }
        }
        foreach ($unlisted as $c) {
            if ((int) $c->sort !== $sort) {
                $c->sort = $sort;
                $c->save();
            }
            $applied[] = ['name' => $c->name, 'sort' => $sort, 'unlisted' => true];
            $sort++;
        }

        return response()->json([
            'ok' => true,
            'reseeded' => count($applied),
            'order' => $applied,
            'missing' => $missing,
        ])->header('Cache-Control', 'no-store');
    }
}
