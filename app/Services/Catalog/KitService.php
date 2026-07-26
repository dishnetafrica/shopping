<?php declare(strict_types=1);

namespace App\Services\Catalog;

use App\BOS\Bos;
use App\Kernel\Events\KitEvents;
use App\Models\KitComponent;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The ONE reader/writer for kits / bundles (Asset Lifecycle · S4a).
 *
 * A kit is a product made of other products. This service defines a kit's parts, reads them back, and
 * "explodes" a kit into its parts (the pure math the sale/quote flow will use in a later slice). It
 * NEVER touches pricing, stock, or the sale engine — it only records what a bundle is made of. The
 * kit_components rows are the source of truth; each change also emits an AUDIT event. Tenant-scoped.
 */
class KitService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** Is this product a bundle? Reads the cheap flag (kept in sync by setComponents). */
    public function isKit(int $productId): bool
    {
        return (bool) Product::where('tenant_id', $this->tenant->id)->where('id', $productId)->value('is_kit');
    }

    /**
     * A kit's parts, ordered, each with its component product eager-loaded.
     * @return \Illuminate\Support\Collection<int,KitComponent>
     */
    public function componentsFor(int $kitProductId)
    {
        return KitComponent::where('tenant_id', $this->tenant->id)
            ->where('kit_product_id', $kitProductId)
            ->with('component')
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /**
     * Replace a kit's parts list in one transaction. Pairs: [['component_product_id'=>int,
     * 'quantity'=>float, 'sort'=>?int], ...]. Duplicate parts are merged (quantities summed) so the
     * unique index can never be violated. A part can't be the kit itself. Marks the product is_kit
     * true (or false when the last part is removed) and emits KitDefined.
     */
    public function setComponents(int $kitProductId, array $pairs): void
    {
        // Merge duplicates by component so re-listing the same part just adds up its quantity.
        $merged = [];   // component_product_id => ['quantity'=>float,'sort'=>int]
        $order  = 0;
        foreach ($pairs as $pr) {
            $cid = (int) ($pr['component_product_id'] ?? 0);
            $qty = (float) ($pr['quantity'] ?? 1);
            if ($cid <= 0 || $cid === $kitProductId || $qty <= 0) {
                continue;
            }
            if (isset($merged[$cid])) {
                $merged[$cid]['quantity'] += $qty;
            } else {
                $merged[$cid] = ['quantity' => $qty, 'sort' => (int) ($pr['sort'] ?? $order)];
                $order++;
            }
        }

        DB::transaction(function () use ($kitProductId, $merged) {
            KitComponent::where('tenant_id', $this->tenant->id)
                ->where('kit_product_id', $kitProductId)->delete();

            foreach ($merged as $cid => $row) {
                KitComponent::create([
                    'tenant_id'            => $this->tenant->id,
                    'kit_product_id'       => $kitProductId,
                    'component_product_id' => $cid,
                    'quantity'             => $row['quantity'],
                    'sort'                 => $row['sort'],
                ]);
            }

            Product::where('tenant_id', $this->tenant->id)->where('id', $kitProductId)
                ->update(['is_kit' => $merged !== []]);
        });

        $this->emitDefined($kitProductId, count($merged));
    }

    /**
     * Explode a kit into its parts, scaled by how many kits. Returns line rows the sale/quote flow can
     * consume later: [['product_id','name','sku','quantity','unit_price'], ...]. Pure read — no writes.
     * @return array<int,array<string,mixed>>
     */
    public function explode(int $kitProductId, float $kitQty = 1.0): array
    {
        if ($kitQty <= 0) {
            $kitQty = 1.0;
        }
        $out = [];
        foreach ($this->componentsFor($kitProductId) as $kc) {
            $p = $kc->component;
            if (! $p) {
                continue;   // a part was deleted — skip it rather than emit a broken line
            }
            $out[] = [
                'product_id' => (int) $kc->component_product_id,
                'name'       => (string) ($p->name ?? ''),
                'sku'        => (string) ($p->sku ?? ''),
                'quantity'   => (float) $kc->quantity * $kitQty,
                'unit_price' => (float) ($p->price ?? 0),
            ];
        }
        return $out;
    }

    /** Every kit with a component count and the summed value of its parts (at current prices). */
    public function listKits(): array
    {
        $kits = Product::where('tenant_id', $this->tenant->id)->where('is_kit', true)
            ->orderBy('name')->get(['id', 'name', 'sku', 'price']);

        $out = [];
        foreach ($kits as $k) {
            $comps = $this->componentsFor((int) $k->id);
            $partsTotal = 0.0;
            foreach ($comps as $c) {
                $partsTotal += (float) $c->quantity * (float) ($c->component->price ?? 0);
            }
            $out[] = [
                'id'          => (int) $k->id,
                'name'        => (string) $k->name,
                'sku'         => (string) ($k->sku ?? ''),
                'price'       => (float) ($k->price ?? 0),
                'components'  => $comps->count(),
                'parts_total' => round($partsTotal, 2),
            ];
        }
        return $out;
    }

    /**
     * Import a kit-components CSV (columns: Kit SKU, Kit Name, Part SKU, Part Name, Quantity — SKU wins,
     * name is the fallback). Rows are grouped by kit; in apply mode each kit's parts are REPLACED (so a
     * re-import is a clean update). Dry-run unless $apply. Never throws on a bad row — it's reported.
     * @return array<string,mixed>
     */
    public function importCsv(string $csv, bool $apply = false): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === [] || trim((string) $lines[0]) === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        $header = array_map(fn ($h) => $this->norm((string) $h), str_getcsv((string) array_shift($lines)));
        // strip a UTF-8 BOM off the first header cell
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        $col = function (array $names) use ($header) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) {
                    return $i;
                }
            }
            return null;
        };

        $iKitSku  = $col(['kit sku', 'kit', 'kit code']);
        $iKitName = $col(['kit name']);
        $iPartSku = $col(['part sku', 'component sku', 'part', 'component', 'part code']);
        $iPartName= $col(['part name', 'component name']);
        $iQty     = $col(['quantity', 'qty', 'count']);

        if ($iKitSku === null && $iKitName === null) {
            return ['ok' => false, 'error' => 'no_kit_column'];
        }
        if ($iPartSku === null && $iPartName === null) {
            return ['ok' => false, 'error' => 'no_part_column'];
        }

        $groups = [];      // kit id => ['kit'=>Product, 'rows'=>[['component_product_id','quantity']]]
        $unresolved = [];
        $rowNo = 1;
        foreach ($lines as $ln) {
            $rowNo++;
            if (trim((string) $ln) === '') {
                continue;
            }
            $c = str_getcsv((string) $ln);
            $kitSku  = $iKitSku  !== null ? trim((string) ($c[$iKitSku] ?? '')) : '';
            $kitName = $iKitName !== null ? trim((string) ($c[$iKitName] ?? '')) : '';
            $partSku = $iPartSku !== null ? trim((string) ($c[$iPartSku] ?? '')) : '';
            $partName= $iPartName!== null ? trim((string) ($c[$iPartName] ?? '')) : '';
            $qty     = $iQty !== null ? (float) preg_replace('/[^0-9.]/', '', (string) ($c[$iQty] ?? '')) : 1.0;
            if ($qty <= 0) {
                $qty = 1.0;
            }
            if (($kitSku === '' && $kitName === '') || ($partSku === '' && $partName === '')) {
                continue;   // blank/spacer row
            }

            $kit  = $this->resolveProduct($kitSku, $kitName);
            $part = $this->resolveProduct($partSku, $partName);
            if (! $kit) {
                $unresolved[] = "row {$rowNo}: kit '" . ($kitSku ?: $kitName) . "' not found";
                continue;
            }
            if (! $part) {
                $unresolved[] = "row {$rowNo}: part '" . ($partSku ?: $partName) . "' not found";
                continue;
            }
            if ((int) $kit->id === (int) $part->id) {
                $unresolved[] = "row {$rowNo}: a kit cannot contain itself";
                continue;
            }

            $key = (int) $kit->id;
            if (! isset($groups[$key])) {
                $groups[$key] = ['kit' => $kit, 'rows' => []];
            }
            $groups[$key]['rows'][] = ['component_product_id' => (int) $part->id, 'quantity' => $qty];
        }

        $kitsCount = count($groups);
        $componentsCount = 0;
        foreach ($groups as $g) {
            $componentsCount += count($g['rows']);
        }

        if ($apply) {
            foreach ($groups as $g) {
                $pairs = [];
                $sort = 0;
                foreach ($g['rows'] as $r) {
                    $pairs[] = ['component_product_id' => $r['component_product_id'], 'quantity' => $r['quantity'], 'sort' => $sort++];
                }
                $this->setComponents((int) $g['kit']->id, $pairs);
            }
        }

        return [
            'ok'         => true,
            'apply'      => $apply,
            'kits'       => $kitsCount,
            'components' => $componentsCount,
            'unresolved' => $unresolved,
        ];
    }

    /** A starter CSV: the headers plus up to two of the tenant's real product SKUs as an example. */
    public function templateCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Kit SKU', 'Kit Name', 'Part SKU', 'Part Name', 'Quantity']);
        $eg = Product::where('tenant_id', $this->tenant->id)->whereNull('archived_at')
            ->orderBy('id')->limit(2)->get(['sku', 'name']);
        if ($eg->count() >= 1) {
            fputcsv($out, ['KIT-EXAMPLE', 'Example bundle', (string) ($eg[0]->sku ?: ''), (string) $eg[0]->name, '1']);
        }
        if ($eg->count() >= 2) {
            fputcsv($out, ['KIT-EXAMPLE', 'Example bundle', (string) ($eg[1]->sku ?: ''), (string) $eg[1]->name, '2']);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }

    /** Round-trip CSV of every existing kit's parts (export → edit → re-import). */
    public function exportCsv(): string
    {
        $rows = KitComponent::where('tenant_id', $this->tenant->id)
            ->with(['kit', 'component'])->orderBy('kit_product_id')->orderBy('sort')->get();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Kit SKU', 'Kit Name', 'Part SKU', 'Part Name', 'Quantity']);
        foreach ($rows as $kc) {
            fputcsv($out, [
                (string) ($kc->kit->sku ?? ''), (string) ($kc->kit->name ?? ''),
                (string) ($kc->component->sku ?? ''), (string) ($kc->component->name ?? ''),
                (string) (0 + (float) $kc->quantity),
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return (string) $csv;
    }

    /** Resolve a product by SKU (case-insensitive) first, then by exact name. Tenant-scoped. */
    private function resolveProduct(string $sku, string $name): ?Product
    {
        $sku = trim($sku);
        $name = trim($name);
        if ($sku !== '') {
            $p = Product::where('tenant_id', $this->tenant->id)
                ->whereRaw('lower(sku) = ?', [mb_strtolower($sku)])->first();
            if ($p) {
                return $p;
            }
        }
        if ($name !== '') {
            $p = Product::where('tenant_id', $this->tenant->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            if ($p) {
                return $p;
            }
        }
        return null;
    }

    private function norm(string $h): string
    {
        $h = strtolower(trim($h));
        $h = str_replace(['_', '-', '.'], ' ', $h);
        return trim((string) preg_replace('/\s+/', ' ', $h));
    }

    /** Emit an AUDIT event (guarded — an audit hiccup can never break the kit write). */
    private function emitDefined(int $kitProductId, int $componentCount): void
    {
        try {
            Bos::emit(KitEvents::DEFINED, [
                'kit_product_id' => $kitProductId,
                'components'     => $componentCount,
            ], [
                'tenant_id'    => $this->tenant->id,
                'subject_type' => 'product',
                'subject_id'   => $kitProductId,
                'actor'        => ['type' => 'user', 'name' => ''],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('kit audit event failed (' . KitEvents::DEFINED . '): ' . $e->getMessage());
        }
    }
}
