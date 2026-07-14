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
            'unit_label' => trim((string) ($d['unit_label'] ?? '')) ?: null,
            'active'     => true,
        ], $this->photoColumns($d) ?: ['image_url' => trim((string) ($d['image'] ?? ''))]));
        $this->ensureCategory($t, $category);

        return $p;
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
        if (array_key_exists('unit_label', $d)) {
            $p->unit_label = trim((string) $d['unit_label']) ?: null;
        }
        $p->save();
        $this->ensureCategory($t, (string) $p->category);

        return $p;
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
