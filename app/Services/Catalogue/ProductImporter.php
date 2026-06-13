<?php
namespace App\Services\Catalogue;

use App\Models\Product;

/**
 * Imports a CSV of products for the CURRENT tenant (tenant_id is stamped
 * automatically by the BelongsToTenant trait). Upserts by product name.
 * Recognised headers (case-insensitive, order-free):
 *   name (required), price, stock, category, keywords, sku, barcode, base_price, active
 * Money/number cells may contain symbols/commas ("UGX 5,000") — they're stripped.
 */
class ProductImporter
{
    public function importCsv(string $path): array
    {
        if (! is_readable($path)) return ['error' => 'file not readable'];
        $fh = fopen($path, 'r');
        if (! $fh) return ['error' => 'cannot open file'];

        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        $header = null; $row = 0;

        while (($cols = fgetcsv($fh)) !== false) {
            $row++;
            if ($header === null) {
                // strip BOM + lowercase headers
                $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cols[0]);
                $header = array_map(fn ($h) => strtolower(trim((string) $h)), $cols);
                continue;
            }
            if (count(array_filter($cols, fn ($c) => trim((string) $c) !== '')) === 0) continue;

            $d = [];
            foreach ($header as $i => $key) $d[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : null;

            $name = $d['name'] ?? null;
            if (! $name) { $skipped++; if (count($errors) < 10) $errors[] = "Row {$row}: missing name"; continue; }

            $price = $this->num($d['price'] ?? 0);
            $attrs = [
                'price'      => $price,
                'base_price' => ($d['base_price'] ?? '') !== '' ? $this->num($d['base_price']) : $price,
                'stock'      => isset($d['stock']) ? (int) $this->num($d['stock']) : 0,
                'category'   => $d['category'] ?: null,
                'sku'        => $d['sku'] ?: null,
                'barcode'    => $d['barcode'] ?: null,
                'keywords'   => $d['keywords'] ?: null,
                'active'     => $this->bool($d['active'] ?? '1'),
            ];

            try {
                $existing = Product::where('name', $name)->first(); // tenant-scoped
                if ($existing) { $existing->update($attrs); $updated++; }
                else { Product::create(array_merge(['name' => $name], $attrs)); $created++; }
            } catch (\Throwable $e) {
                $skipped++; if (count($errors) < 10) $errors[] = "Row {$row}: ".$e->getMessage();
            }
        }
        fclose($fh);
        return compact('created', 'updated', 'skipped', 'errors');
    }

    private function num($v): float { return (float) preg_replace('/[^0-9.\-]/', '', (string) $v); }

    private function bool($v): bool
    {
        $v = strtolower(trim((string) $v));
        return ! in_array($v, ['0', 'no', 'false', 'inactive', 'off', 'n', ''], true);
    }
}
