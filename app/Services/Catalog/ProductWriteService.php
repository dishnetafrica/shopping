<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Thin catalogue write service — the ONE place v3 product create/update/archive goes through.
 * Mirrors the legacy panel's addProduct/updateProduct field mapping exactly (PanelApiController),
 * so behaviour is identical; the Product model's own observer keeps the stock ledger in sync.
 * No pricing/stock business logic lives here — it only maps validated input onto the model.
 */
final class ProductWriteService
{
    /** Create a catalogue product for the tenant. Requires name + positive price. */
    public function create(Tenant $t, array $d): Product
    {
        $name = trim((string) ($d['name'] ?? ''));
        $price = (float) ($d['price'] ?? 0);
        if ($name === '' || $price <= 0) {
            throw new \InvalidArgumentException('name_and_price_required');
        }

        $category = trim((string) ($d['category'] ?? ''));
        $p = Product::create(array_merge([
            'tenant_id'  => (int) $t->id,
            'name'       => $name,
            'category'   => $category,
            'keywords'   => trim((string) ($d['keywords'] ?? '')),
            'base_price' => $price,
            'price'      => $price,
            'cost_price' => isset($d['cost']) && $d['cost'] !== '' ? (float) $d['cost'] : null,
            'stock'      => (int) ($d['stock'] ?? 0),
            'moq'        => isset($d['moq']) && $d['moq'] !== '' ? max(1, (int) $d['moq']) : null,
            'pack_size'  => isset($d['pack_size']) && $d['pack_size'] !== '' ? max(1, (int) $d['pack_size']) : null,
            'piece_price' => isset($d['piece_price']) && $d['piece_price'] !== '' && (float) $d['piece_price'] > 0 ? (float) $d['piece_price'] : null,
            'reorder_point' => isset($d['reorder_point']) && $d['reorder_point'] !== '' ? max(0, (int) $d['reorder_point']) : null,
            'unit_label' => trim((string) ($d['unit_label'] ?? '')) ?: null,
            // Default on, as always. A caller may create it switched off (e.g. an import awaiting review).
            'active'     => array_key_exists('active', $d) ? (bool) $d['active'] : true,
        ], $this->detailColumns($d), $this->photoColumns($d) ?: ['image_url' => trim((string) ($d['image'] ?? ''))]));
        $this->ensureCategory($t, $category);

        return $p;
    }

    /**
     * v4.28 — catalogue detail: brand, sku, barcode, description, unit_size, origin_country.
     * Kept in one place so the v3 workspace and the legacy panel cannot drift apart on which
     * fields a product carries. unit_size/origin_country are Schema-guarded because they are
     * new columns and a box may not have run the migration yet.
     *
     * @param  bool  $onlyPresent  true (update) → return only keys actually supplied, so a
     *                             partial edit never blanks a field the caller did not send.
     * @return array<string,?string>
     */
    private function detailColumns(array $d, bool $onlyPresent = false): array
    {
        $out = [];

        // v4.54 — 'hidden' takes the product off every customer-facing surface without deactivating
        // it. Handled ahead of the string columns because it is a boolean, and the loop below writes
        // '' as null, which for this column would read as visible and quietly un-hide things.
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'hidden')
            && (! $onlyPresent || array_key_exists('hidden', $d))) {
            $out['hidden'] = in_array(mb_strtolower(trim((string) ($d['hidden'] ?? ''))), ['1', 'yes', 'y', 'true', 'on', 'hidden'], true);
        }

        // Internet-provider catalogue: paid once or monthly, which service, minimum contract. Only the
        // listed values are kept; anything else is stored as "not set". Schema-guarded like the rest.
        foreach (['billing' => \App\Services\Isp\IspQuote::BILLING, 'service_line' => \App\Services\Isp\IspQuote::SERVICE_LINES] as $col => $allowed) {
            if (($onlyPresent && ! array_key_exists($col, $d)) || ! \Illuminate\Support\Facades\Schema::hasColumn('products', $col)) {
                continue;
            }
            $v = mb_strtolower(trim((string) ($d[$col] ?? '')));
            if ($v === '' && ! $onlyPresent) {
                continue;
            }
            $out[$col] = in_array($v, $allowed, true) ? $v : null;
        }
        if ((! $onlyPresent || array_key_exists('min_term_months', $d)) && \Illuminate\Support\Facades\Schema::hasColumn('products', 'min_term_months')) {
            $m = trim((string) ($d['min_term_months'] ?? ''));
            if ($m !== '' || $onlyPresent) {
                $out['min_term_months'] = ($m === '' || (int) $m <= 0) ? null : min(120, (int) $m);
            }
        }

        foreach (['brand', 'sku', 'barcode', 'description', 'unit_size', 'origin_country'] as $col) {
            if ($onlyPresent && ! array_key_exists($col, $d)) {
                continue;
            }
            if (in_array($col, ['unit_size', 'origin_country'], true)
                && ! \Illuminate\Support\Facades\Schema::hasColumn('products', $col)) {
                continue;
            }
            $v = trim((string) ($d[$col] ?? ''));
            if ($v === '' && ! $onlyPresent) {
                continue;                       // create: leave the column at its default
            }
            $out[$col] = $v ?: null;
        }

        return $out;
    }

    /** Make sure a category typed on a product also exists as a Category record (so it lists + filters). */
    public function ensureCategory(Tenant $t, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        Category::firstOrCreate(
            ['tenant_id' => (int) $t->id, 'name' => $name],
            ['active' => true, 'sort' => 0],
        );
    }

    /**
     * Map a `photos` array (up to 4) onto the fixed image slots: first → image_url (the one shown
     * in lists/bot), the rest → gallery_1..3. Falls back to a single `image` string. Returns [] when
     * neither key is present, so update() leaves photos untouched.
     *
     * @return array<string,?string>
     */
    private function photoColumns(array $d): array
    {
        if (array_key_exists('photos', $d) && is_array($d['photos'])) {
            $u = array_values(array_filter(array_map(fn ($x) => trim((string) $x), $d['photos']), fn ($x) => $x !== ''));

            return [
                'image_url' => $u[0] ?? '',
                'gallery_1' => $u[1] ?? null,
                'gallery_2' => $u[2] ?? null,
                'gallery_3' => $u[3] ?? null,
            ];
        }
        if (array_key_exists('image', $d)) {
            return ['image_url' => trim((string) $d['image'])];
        }

        return [];
    }

    /** Update only the fields present in $d (blank/absent = left untouched). */
    public function update(Tenant $t, Product $p, array $d): Product
    {
        if (array_key_exists('name', $d) && trim((string) $d['name']) !== '') {
            $p->name = trim((string) $d['name']);
        }
        if (array_key_exists('category', $d)) {
            $p->category = trim((string) $d['category']);
        }
        if (array_key_exists('cost', $d)) {
            $p->cost_price = ($d['cost'] === '' || $d['cost'] === null) ? null : (float) $d['cost'];
        }
        if (array_key_exists('price', $d) && $d['price'] !== '' && (float) $d['price'] > 0) {
            $p->base_price = (float) $d['price'];
            $p->price = (float) $d['price'];
        }
        if (array_key_exists('stock', $d) && $d['stock'] !== '') {
            $p->stock = (int) $d['stock'];
        }
        foreach ($this->photoColumns($d) as $col => $val) {
            $p->{$col} = $val;
        }
        if (array_key_exists('moq', $d)) {
            $p->moq = $d['moq'] === '' || $d['moq'] === null ? null : max(1, (int) $d['moq']);
        }
        if (array_key_exists('pack_size', $d)) {
            $p->pack_size = $d['pack_size'] === '' || $d['pack_size'] === null ? null : max(1, (int) $d['pack_size']);
        }
        // Explicit per-piece retail price. A blank field CLEARS it (back to auto from pack×markup);
        // a non-positive value is treated as clear too. Guarded on the column so an older DB is safe.
        if (array_key_exists('piece_price', $d) && \Illuminate\Support\Facades\Schema::hasColumn('products', 'piece_price')) {
            $p->piece_price = ($d['piece_price'] === '' || $d['piece_price'] === null || (float) $d['piece_price'] <= 0) ? null : (float) $d['piece_price'];
        }
        if (array_key_exists('reorder_point', $d)) {
            // 0 is a legitimate floor ("only reorder once genuinely out"), so it is kept, not nulled —
            // only a blank field clears the reorder point back to "no floor set".
            $p->reorder_point = $d['reorder_point'] === '' || $d['reorder_point'] === null ? null : max(0, (int) $d['reorder_point']);
        }
        if (array_key_exists('unit_label', $d)) {
            $p->unit_label = trim((string) $d['unit_label']) ?: null;
        }
        foreach ($this->detailColumns($d, true) as $col => $val) {
            $p->{$col} = $val;
        }
        $p->save();
        $this->ensureCategory($t, (string) $p->category);

        return $p;
    }

    /** Switch a product on (sellable, quotable, shown) — the counterpart of archive(). Clears an archive stamp. */
    public function activate(Tenant $t, Product $p): void
    {
        $p->active = true;
        if (in_array('archived_at', $p->getFillable(), true) || $p->isFillable('archived_at')) {
            $p->archived_at = null;
        }
        $p->save();
    }

    /** Non-destructive removal: deactivate + timestamp, preserving order history. */
    public function archive(Tenant $t, Product $p): void
    {
        $p->active = false;
        if (in_array('archived_at', $p->getFillable(), true) || $p->isFillable('archived_at')) {
            $p->archived_at = now();
        }
        $p->save();
    }
}
