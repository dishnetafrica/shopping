<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Catalogue export — CSV, a full ZIP (CSV + photos), or a photos-only ZIP. The CSV columns match the
 * ZIP import (productsImport) so a catalogue round-trips: export → edit in Excel → re-import. Read-only.
 */
final class CatalogExport
{
    private const HEADERS = ['Product Name', 'Category', 'Price UGX', 'Cost', 'Stock', 'SKU', 'Keywords', 'Unit', 'Pack Size', 'MOQ', 'Image'];

    /** @return \Illuminate\Support\Collection<int,Product> */
    private function products(Tenant $t)
    {
        return Product::where('tenant_id', $t->id)->whereNull('archived_at')->orderBy('name')->get();
    }

    /** CSV string. When $imageAsFile is true the Image column holds a local filename (for the ZIP); else the URL. */
    public function csv(Tenant $t, bool $imageAsFile = false): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::HEADERS);
        $used = [];
        foreach ($this->products($t) as $p) {
            $img = '';
            if ($imageAsFile) {
                $img = $this->photoFilename($p, $used, false);   // '' if no photo
            } else {
                $img = (string) ($p->image_url ?: '');
            }
            fputcsv($out, [
                (string) $p->name,
                (string) ($p->category ?: ''),
                $p->price !== null ? (string) (0 + $p->price) : '',
                $p->cost_price !== null ? (string) (0 + $p->cost_price) : '',
                $p->stock !== null ? (string) (int) $p->stock : '0',
                (string) ($p->sku ?: ''),
                (string) ($p->keywords ?: ''),
                (string) ($p->unit_label ?: ''),
                $p->pack_size !== null ? (string) (0 + $p->pack_size) : '',
                $p->moq !== null ? (string) (0 + $p->moq) : '',
                $img,
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Full catalogue ZIP: products.csv (Image = local filename) + the image files. */
    public function zip(Tenant $t, bool $withPhotos = true): ?string
    {
        if (! class_exists('ZipArchive')) { return null; }
        $tmp = tempnam(sys_get_temp_dir(), 'cat');
        $za = new \ZipArchive();
        if ($za->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }

        $za->addFromString('products.csv', $this->csv($t, $withPhotos));
        if ($withPhotos) {
            $this->addPhotos($za, $t);
        }
        $za->close();
        $bytes = @file_get_contents($tmp);
        @unlink($tmp);

        return $bytes ?: null;
    }

    /** Photos-only ZIP (named by product). */
    public function photosZip(Tenant $t): ?string
    {
        if (! class_exists('ZipArchive')) { return null; }
        $tmp = tempnam(sys_get_temp_dir(), 'pho');
        $za = new \ZipArchive();
        if ($za->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { @unlink($tmp); return null; }
        $n = $this->addPhotos($za, $t);
        $za->close();
        $bytes = ($n > 0) ? @file_get_contents($tmp) : null;
        @unlink($tmp);

        return $bytes ?: null;
    }

    /** Add each product's main photo (+ gallery) to the archive, named by product. Returns count added. */
    private function addPhotos(\ZipArchive $za, Tenant $t): int
    {
        $used = []; $added = 0;
        foreach ($this->products($t) as $p) {
            $urls = array_filter([$p->image_url, $p->gallery_1, $p->gallery_2, $p->gallery_3]);
            $first = true;
            foreach ($urls as $url) {
                $path = $this->storagePath((string) $url);
                if ($path === null) { continue; }
                try {
                    if (! Storage::disk('public')->exists($path)) { continue; }
                    $bytes = Storage::disk('public')->get($path);
                } catch (\Throwable $e) { continue; }
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
                $fname = $this->slug((string) $p->name) . ($first ? '' : ('-' . ($added + 1)));
                $fname = $this->uniqueName($fname . '.' . $ext, $used);
                $za->addFromString($fname, $bytes);
                $added++; $first = false;
            }
        }

        return $added;
    }

    /** The public-disk path behind a stored image URL (…/storage/products/…), or null. */
    private function storagePath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') { return null; }
        $pos = strpos($url, '/storage/');
        if ($pos !== false) { return ltrim(substr($url, $pos + strlen('/storage/')), '/'); }
        // already a relative path?
        if (str_starts_with($url, 'products/')) { return $url; }

        return null;
    }

    /** Filename for a product's photo in the ZIP ('' if it has none). */
    private function photoFilename(Product $p, array &$used, bool $registerOnly): string
    {
        $path = $this->storagePath((string) ($p->image_url ?: ''));
        if ($path === null) { return ''; }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';

        return $this->uniqueName($this->slug((string) $p->name) . '.' . $ext, $used);
    }

    private function uniqueName(string $name, array &$used): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name; $i = 2;
        while (isset($used[strtolower($candidate)])) {
            $candidate = $base . '-' . $i . ($ext ? ('.' . $ext) : '');
            $i++;
        }
        $used[strtolower($candidate)] = true;

        return $candidate;
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);

        return trim((string) $s, '-') ?: 'product';
    }
}
