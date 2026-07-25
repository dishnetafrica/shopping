<?php declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductSpec;
use App\Models\SpecDefinition;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * The ONE reader/writer for product specifications (Asset Lifecycle · core capability).
 *
 * A spec definition is the field (Wattage / W / number); a product spec is a product's value against
 * it. Number specs are stored in value_number (for range filter/sort) AND value_text (display); text,
 * enum and boolean specs use value_text. Tenant scoping is automatic (BelongsToTenant) — the models
 * filter and stamp tenant_id from the active TenantContext.
 */
class ProductSpecService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    // ── Pure helpers (no DB — unit-testable in isolation) ──────────────────────────────────────

    /** Normalise a raw input into {value_text, value_number} for the given data type. */
    public static function coerce(string $dataType, mixed $raw): array
    {
        $text = $raw === null ? '' : trim((string) $raw);
        if ($text === '') {
            return ['value_text' => null, 'value_number' => null];
        }
        if ($dataType === 'number') {
            $n = self::parseNumber($text);
            return ['value_text' => $text, 'value_number' => $n];
        }
        if ($dataType === 'boolean') {
            $b = in_array(mb_strtolower($text), ['1', 'true', 'yes', 'on', 'y'], true);
            return ['value_text' => $b ? 'Yes' : 'No', 'value_number' => $b ? 1.0 : 0.0];
        }
        // text | enum
        return ['value_text' => $text, 'value_number' => null];
    }

    /** Pull the first numeric value out of a string (handles "550", "550 W", "1,200"). */
    public static function parseNumber(string $s): ?float
    {
        $clean = str_replace(',', '', $s);
        if (! preg_match('/-?\d+(\.\d+)?/', $clean, $m)) {
            return null;
        }
        return (float) $m[0];
    }

    /** A URL/DB-safe key from a label, if the caller didn't supply one. */
    public static function keyFrom(string $label): string
    {
        return Str::slug($label, '_') ?: 'spec';
    }

    // ── Catalogue ──────────────────────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int,SpecDefinition> */
    public function definitions(bool $activeOnly = true)
    {
        $q = SpecDefinition::where('tenant_id', $this->tenant->id);
        if ($activeOnly) {
            $q->where('active', true);
        }
        return $q->orderBy('display_order')->orderBy('label')->get();
    }

    /** Create or update a spec definition (upsert by key). */
    public function defineDefinition(array $d): SpecDefinition
    {
        $key      = trim((string) ($d['key'] ?? '')) ?: self::keyFrom((string) ($d['label'] ?? ''));
        $dataType = in_array(($d['data_type'] ?? 'text'), SpecDefinition::TYPES, true) ? $d['data_type'] : 'text';
        $options  = $dataType === 'enum' ? array_values(array_filter((array) ($d['options'] ?? []))) : null;

        return SpecDefinition::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => $key],
            [
                'label'         => (string) ($d['label'] ?? $key),
                'unit'          => ($u = trim((string) ($d['unit'] ?? ''))) !== '' ? $u : null,
                'data_type'     => $dataType,
                'options'       => $options,
                'group'         => ($g = trim((string) ($d['group'] ?? ''))) !== '' ? $g : null,
                'filterable'    => (bool) ($d['filterable'] ?? true),
                'searchable'    => (bool) ($d['searchable'] ?? true),
                'display_order' => (int) ($d['display_order'] ?? 0),
                'active'        => (bool) ($d['active'] ?? true),
            ]
        );
    }

    // ── Per-product values ───────────────────────────────────────────────────────────────────────

    /**
     * A product's specs, ready for display: label, value, unit, group — ordered by the catalogue.
     * @return array<int,array{key:string,label:string,value:?string,unit:?string,group:?string}>
     */
    public function specsFor(int $productId): array
    {
        $defs = $this->definitions(false)->keyBy('id');
        $rows = ProductSpec::where('tenant_id', $this->tenant->id)
            ->where('product_id', $productId)->get();

        $out = [];
        foreach ($rows as $r) {
            $def = $defs->get($r->spec_definition_id);
            if (! $def) continue;
            $out[] = [
                'key'   => $def->key,
                'label' => $def->label,
                'value' => $r->value_text,
                'unit'  => $def->unit,
                'group' => $def->group,
                'order' => $def->display_order,
            ];
        }
        usort($out, fn ($a, $b) => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
        return array_map(fn ($r) => \Illuminate\Support\Arr::except($r, ['order']), $out);
    }

    /**
     * Set a product's spec values. $pairs is [key => value]. A null/empty value clears that spec.
     * Unknown keys are ignored. Returns the number of specs written (not cleared).
     */
    public function setProductSpecs(int $productId, array $pairs): int
    {
        $defs = $this->definitions(false)->keyBy('key');
        $written = 0;
        foreach ($pairs as $key => $raw) {
            $def = $defs->get($key);
            if (! $def) continue;
            $c = self::coerce($def->data_type, $raw);
            if ($c['value_text'] === null) {
                ProductSpec::where('tenant_id', $this->tenant->id)
                    ->where('product_id', $productId)->where('spec_definition_id', $def->id)->delete();
                continue;
            }
            ProductSpec::updateOrCreate(
                ['tenant_id' => $this->tenant->id, 'product_id' => $productId, 'spec_definition_id' => $def->id],
                ['value_text' => $c['value_text'], 'value_number' => $c['value_number']]
            );
            $written++;
        }
        return $written;
    }

    /**
     * Product IDs matching spec filters. Each filter is keyed by spec key:
     *   ['wattage' => ['min'=>400], 'battery_chemistry' => ['eq'=>'LiFePO4']]
     * Numeric specs accept min/max; text/enum accept eq (exact) or contains (LIKE). A product must
     * match ALL given filters. Returns matching product IDs (intersection across filters).
     * @return array<int,int>
     */
    public function filterProductIds(array $filters): array
    {
        $defs = $this->definitions(false)->keyBy('key');
        $idSets = [];
        foreach ($filters as $key => $cond) {
            $def = $defs->get($key);
            if (! $def || ! is_array($cond)) continue;
            $q = ProductSpec::where('tenant_id', $this->tenant->id)
                ->where('spec_definition_id', $def->id);
            if (isset($cond['min'])) $q->where('value_number', '>=', (float) $cond['min']);
            if (isset($cond['max'])) $q->where('value_number', '<=', (float) $cond['max']);
            if (isset($cond['eq']) && $cond['eq'] !== '') $q->where('value_text', (string) $cond['eq']);
            if (isset($cond['contains']) && $cond['contains'] !== '') $q->where('value_text', 'like', '%' . $cond['contains'] . '%');
            $idSets[] = $q->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        }
        if ($idSets === []) return [];
        $result = array_shift($idSets);
        foreach ($idSets as $set) {
            $result = array_values(array_intersect($result, $set));
        }
        return array_values(array_unique($result));
    }
}
