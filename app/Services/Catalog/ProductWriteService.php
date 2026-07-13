<?php

namespace App\Services\Catalog;

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

        return Product::create([
            'tenant_id'  => (int) $t->id,
            'name'       => $name,
            'category'   => trim((string) ($d['category'] ?? '')),
            'keywords'   => trim((string) ($d['keywords'] ?? '')),
            'base_price' => $price,
            'price'      => $price,
            'cost_price' => isset($d['cost']) && $d['cost'] !== '' ? (float) $d['cost'] : null,
            'stock'      => (int) ($d['stock'] ?? 0),
            'image_url'  => trim((string) ($d['image'] ?? '')),
            'moq'        => isset($d['moq']) && $d['moq'] !== '' ? max(1, (int) $d['moq']) : null,
            'pack_size'  => isset($d['pack_size']) && $d['pack_size'] !== '' ? max(1, (int) $d['pack_size']) : null,
            'unit_label' => trim((string) ($d['unit_label'] ?? '')) ?: null,
            'active'     => true,
        ]);
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
        if (array_key_exists('image', $d)) {
            $p->image_url = trim((string) $d['image']);
        }
        if (array_key_exists('moq', $d)) {
            $p->moq = $d['moq'] === '' || $d['moq'] === null ? null : max(1, (int) $d['moq']);
        }
        if (array_key_exists('unit_label', $d)) {
            $p->unit_label = trim((string) $d['unit_label']) ?: null;
        }
        $p->save();

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
