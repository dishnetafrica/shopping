<?php
namespace App\Services\Catalogue;

use App\Models\Product;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Imports a supermarket pricelist CSV for the CURRENT tenant.
 * Understands the common Uganda POS export headers out of the box
 * (Product Name, Price_UGX, Cost, Item_Code, Barcode_1, Image, ...) as well
 * as simple headers (name, price, stock, ...). Header matching is by alias,
 * case/space/underscore-insensitive.
 *
 * mode = 'replace' : delete this tenant's products, then bulk insert (fast, for full pricelists)
 * mode = 'merge'   : upsert row-by-row by name (for small edits)
 */
class ProductImporter
{
    /** canonical field => list of accepted header names (normalised) */
    private array $aliases = [
        'name'       => ['name', 'product name', 'productname', 'item name', 'itemname'],
        'description'=> ['description', 'desc', 'details', 'about', 'blurb'],
        'variant'    => ['variant', 'variation'],
        'price'      => ['price', 'price ugx', 'priceugx', 'sell price', 'sellprice', 'selling price', 'retail', 'mrp', 'rate'],
        // v4.37 — 'cost' used to live on this line, so a Cost column landed in base_price: the one
        // field the storefront detail page and the panel product list read IN PREFERENCE TO price.
        // A catalogue exported and re-imported came back advertising what you paid as what you
        // charge, with every cost_price left null. A cost is a cost.
        'base_price' => ['base price', 'baseprice'],
        'cost_price' => ['cost', 'cost price', 'costprice', 'buying price', 'buy price', 'purchase price'],
        'stock'      => ['stock', 'qty', 'quantity', 'sell qty', 'sellqty', 'on hand', 'onhand'],
        'category'   => ['category', 'item group', 'itemgroup', 'group', 'department'],
        'keywords'   => ['keywords', 'tags', 'search'],
        'sku'        => ['sku', 'item code', 'itemcode', 'code'],
        'barcode'    => ['barcode', 'barcode 1', 'barcode1', 'barcode_1', 'ean', 'upc'],
        'image_url'  => ['image', 'image url', 'imageurl', 'image link', 'photo', 'picture'],
        // v5.06 — the extra shots. `products.gallery_1..3` have existed since v4.2 and the product
        // page renders them, but no importer could fill them: a supplier sheet with four photos of
        // one item could only ever land the first, and the other three had to be uploaded by hand,
        // one product at a time. The columns were always there; only the alias was missing.
        'gallery_1'  => ['gallery 1', 'gallery1', 'image 2', 'image2', 'photo 2', 'photo2'],
        'gallery_2'  => ['gallery 2', 'gallery2', 'image 3', 'image3', 'photo 3', 'photo3'],
        'gallery_3'  => ['gallery 3', 'gallery3', 'image 4', 'image4', 'photo 4', 'photo4'],
        'active'     => ['active', 'enabled', 'status'],
        'moq'        => ['moq', 'min order', 'minimum order', 'min qty', 'minimum qty', 'min order qty'],
        'pack_size'  => ['pack size', 'packsize', 'pack', 'units per pack', 'pcs per carton', 'pcs in ctn', 'pcs in carton', 'pieces per pack'],
        'unit_label' => ['unit', 'selling unit', 'uom', 'pack unit', 'sold as'],
        // v4.28 — brand was missing entirely, so a supplier sheet with a Brand column lost it.
        'brand'      => ['brand', 'make', 'manufacturer'],
        'unit_size'  => ['size', 'unit size', 'unitsize', 'pack size ml', 'volume', 'weight', 'net weight', 'content', 'contents'],
        'origin_country' => ['origin', 'country', 'country of origin', 'countryoforigin', 'made in', 'origin country'],
    ];

    public function importCsv(string $path, string $mode = 'replace'): array
    {
        if (! is_readable($path)) return ['error' => 'file not readable'];
        $fh = fopen($path, 'r');
        if (! $fh) return ['error' => 'cannot open file'];

        // header row
        $rawHeader = fgetcsv($fh);
        if (! $rawHeader) { fclose($fh); return ['error' => 'empty file']; }
        $rawHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rawHeader[0]);
        $map = $this->resolveColumns($rawHeader);
        if (! isset($map['name'])) {
            fclose($fh);
            return ['error' => 'no product-name column found (looked for "Product Name" / "name")'];
        }

        $tenantId = app(TenantContext::class)->id() ?? auth()->user()?->tenant_id;
        if (! $tenantId) { fclose($fh); return ['error' => 'no active business — please log out and back in, then retry']; }
        $now = now();
        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        $batch = [];

        if ($mode === 'replace') {
            Product::query()->delete(); // tenant-scoped delete
        }

        while (($cols = fgetcsv($fh)) !== false) {
            if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) continue;

            $get = fn ($field) => isset($map[$field], $cols[$map[$field]]) ? trim((string) $cols[$map[$field]]) : '';

            $name = $get('name');
            $variant = $get('variant');
            if ($variant !== '') $name = trim($name.' '.$variant);
            if ($name === '') { $skipped++; continue; }

            $price = $this->num($get('price'));
            $row = [
                'tenant_id'  => $tenantId,
                'name'       => mb_substr($name, 0, 255),
                'description'=> $get('description') !== '' ? mb_substr($get('description'), 0, 1000) : null,
                'sku'        => $get('sku') ?: null,
                'category'   => $get('category') ?: null,
                'price'      => $price,
                'base_price' => $get('base_price') !== '' ? $this->num($get('base_price')) : $price,
                'cost_price' => $get('cost_price') !== '' ? $this->num($get('cost_price')) : null,
                'stock'      => $get('stock') !== '' ? (int) $this->num($get('stock')) : null,
                'barcode'    => $this->cleanBarcode($get('barcode')),
                'keywords'   => $get('keywords') ?: null,
                'image_url'  => $get('image_url') ?: null,
                'gallery_1'  => $get('gallery_1') ?: null,
                'gallery_2'  => $get('gallery_2') ?: null,
                'gallery_3'  => $get('gallery_3') ?: null,
                'active'     => $get('active') === '' ? true : $this->bool($get('active')),
                'moq'        => $get('moq') !== '' ? max(1, (int) $this->num($get('moq'))) : null,
                'pack_size'  => $get('pack_size') !== '' ? max(1, (int) $this->num($get('pack_size'))) : null,
                'unit_label' => $get('unit_label') ?: null,
                'brand'      => $get('brand') ?: null,
            ];
            // v4.28 — Schema-guarded: a raw insert() bypasses the model, so an unmigrated
            // box would hard-fail the whole import rather than just miss two columns.
            // A raw insert() bypasses the model, so an unmigrated box must not be handed a column
            // the table does not have — it would hard-fail the whole import, not skip one field.
            if (! \Illuminate\Support\Facades\Schema::hasColumn('products', 'cost_price')) {
                unset($row['cost_price']);
            }
            // v5.06 — TRUNCATE, like name (255) and description (1000) above.
            //
            // These two are the narrowest columns the importer writes — unit_size is varchar(40)
            // and origin_country varchar(60) — and they were the only ones written raw. A supplier
            // sheet with a Size cell of "3500W inverter + 25.6V 120Ah / 3.07kWh battery" (46) made
            // Postgres refuse the row, and because a bulk insert() is one statement the WHOLE
            // import died with an uncaught 500 and no clue on screen. One long cell in one row of
            // thirty-two took the lot down. Cutting the value keeps the other 31 products and the
            // rest of that one; the alternative — losing the import — helps nobody.
            $widths = ['unit_size' => 40, 'origin_country' => 60];
            foreach ($widths as $newCol => $max) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('products', $newCol)) {
                    $v = $get($newCol);
                    $row[$newCol] = $v !== '' ? mb_substr($v, 0, $max) : null;
                }
            }

            if ($mode === 'merge') {
                $existing = Product::where('name', $row['name'])->first();
                unset($row['tenant_id']);
                if ($existing) {
                    // Only overwrite columns actually provided in the CSV — a blank cell keeps the
                    // existing value, so re-importing never wipes photos, stock, cost, etc.
                    $upd = ['name' => $row['name']];
                    if ($get('price') !== '')      $upd['price']      = $price;
                    if ($get('base_price') !== '') $upd['base_price'] = $this->num($get('base_price'));
                    if ($get('cost_price') !== '') $upd['cost_price'] = $this->num($get('cost_price'));
                    foreach (['description', 'sku', 'category', 'keywords', 'unit_label', 'brand', 'unit_size', 'origin_country'] as $f) {
                        if (array_key_exists($f, $row) && $get($f) !== '') $upd[$f] = $row[$f];
                    }
                    if ($get('stock') !== '')     $upd['stock']     = $row['stock'];
                    if ($get('barcode') !== '')   $upd['barcode']   = $row['barcode'];
                    if ($get('image_url') !== '') $upd['image_url'] = $row['image_url'];
                    foreach (['gallery_1', 'gallery_2', 'gallery_3'] as $g) {
                        if ($get($g) !== '') $upd[$g] = $row[$g];
                    }
                    if ($get('active') !== '')    $upd['active']    = $row['active'];
                    if ($get('moq') !== '')       $upd['moq']       = $row['moq'];
                    if ($get('pack_size') !== '') $upd['pack_size'] = $row['pack_size'];
                    $existing->update($upd);
                    $updated++;
                } else { Product::create($row); $created++; }
            } else {
                $row['created_at'] = $now; $row['updated_at'] = $now;
                $batch[] = $row;
                $created++;
                if (count($batch) >= 500) { Product::insert($batch); $batch = []; }
            }
        }
        if ($batch) Product::insert($batch);
        fclose($fh);

        return compact('created', 'updated', 'skipped', 'errors');
    }

    private function resolveColumns(array $header): array
    {
        $norm = array_map(fn ($h) => $this->normalize($h), $header);
        /* v4.37 — the exporter now names the currency in the heading ("Price USD", "Cost UGX")
           so nobody re-types dollar prices as shillings. Strip a trailing currency code before
           matching, and every past and future heading resolves without enumerating them all. */
        $bare = array_map(
            fn ($h) => trim((string) preg_replace('/\s*\(?\b[a-z]{3}\b\)?$/', '', $h)) ?: $h,
            $norm
        );
        $map = [];
        foreach ($this->aliases as $field => $names) {
            foreach ($names as $alias) {
                $idx = array_search($alias, $norm, true);
                if ($idx === false) $idx = array_search($alias, $bare, true);
                if ($idx !== false) { $map[$field] = $idx; break; }
            }
        }
        return $map;
    }

    private function normalize(string $h): string
    {
        $h = strtolower(trim($h));
        $h = str_replace(['_', '-', '.'], ' ', $h);
        return trim(preg_replace('/\s+/', ' ', $h));
    }

    private function num($v): float { return (float) preg_replace('/[^0-9.\-]/', '', (string) $v); }

    private function bool($v): bool
    {
        $v = strtolower(trim((string) $v));
        return ! in_array($v, ['0', 'no', 'false', 'inactive', 'off', 'n', 'disabled', ''], true);
    }

    /** POS exports often mangle barcodes to "3.59E+12" — drop those, keep clean digit strings. */
    private function cleanBarcode(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || stripos($v, 'e+') !== false) return null;
        return preg_match('/^\d{6,}$/', $v) ? $v : null;
    }
}
