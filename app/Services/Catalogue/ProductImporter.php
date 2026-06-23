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
        'base_price' => ['base price', 'baseprice', 'cost', 'cost price', 'costprice', 'buying price'],
        'stock'      => ['stock', 'qty', 'quantity', 'sell qty', 'sellqty', 'on hand', 'onhand'],
        'category'   => ['category', 'item group', 'itemgroup', 'group', 'department'],
        'keywords'   => ['keywords', 'tags', 'search'],
        'sku'        => ['sku', 'item code', 'itemcode', 'code'],
        'barcode'    => ['barcode', 'barcode 1', 'barcode1', 'barcode_1', 'ean', 'upc'],
        'image_url'  => ['image', 'image url', 'imageurl', 'image link', 'photo', 'picture'],
        'active'     => ['active', 'enabled', 'status'],
        'moq'        => ['moq', 'min order', 'minimum order', 'min qty', 'minimum qty', 'min order qty'],
        'pack_size'  => ['pack size', 'packsize', 'pack', 'units per pack', 'pcs per carton', 'pcs in ctn', 'pcs in carton', 'pieces per pack'],
        'unit_label' => ['unit', 'selling unit', 'uom', 'pack unit', 'sold as'],
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
                'stock'      => $get('stock') !== '' ? (int) $this->num($get('stock')) : null,
                'barcode'    => $this->cleanBarcode($get('barcode')),
                'keywords'   => $get('keywords') ?: null,
                'image_url'  => $get('image_url') ?: null,
                'active'     => $get('active') === '' ? true : $this->bool($get('active')),
                'moq'        => $get('moq') !== '' ? max(1, (int) $this->num($get('moq'))) : null,
                'pack_size'  => $get('pack_size') !== '' ? max(1, (int) $this->num($get('pack_size'))) : null,
                'unit_label' => $get('unit_label') ?: null,
            ];

            if ($mode === 'merge') {
                $existing = Product::where('name', $row['name'])->first();
                unset($row['tenant_id']);
                if ($existing) {
                    // Only overwrite columns actually provided in the CSV — a blank cell keeps the
                    // existing value, so re-importing never wipes photos, stock, cost, etc.
                    $upd = ['name' => $row['name']];
                    if ($get('price') !== '')      $upd['price']      = $price;
                    if ($get('base_price') !== '') $upd['base_price'] = $this->num($get('base_price'));
                    foreach (['description', 'sku', 'category', 'keywords', 'unit_label'] as $f) {
                        if ($get($f) !== '') $upd[$f] = $row[$f];
                    }
                    if ($get('stock') !== '')     $upd['stock']     = $row['stock'];
                    if ($get('barcode') !== '')   $upd['barcode']   = $row['barcode'];
                    if ($get('image_url') !== '') $upd['image_url'] = $row['image_url'];
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
        $map = [];
        foreach ($this->aliases as $field => $names) {
            foreach ($names as $alias) {
                $idx = array_search($alias, $norm, true);
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
