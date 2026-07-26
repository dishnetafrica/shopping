<?php declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Services\Catalog\KitService;

/**
 * Project Quotes (Asset Lifecycle · Phase 2 · S8).
 *
 * A project quote is a NORMAL quotation — priced, numbered, PDF'd and sent by the FROZEN quotation
 * engine (QuotationWriteService) exactly as any other quote — whose lines are organized into SECTIONS.
 * We reuse the engine untouched: resolve every line's price up front, hand the flat priced lines to
 * createFor(), then record the section grouping in the quotation's `meta` (jsonb, additive — no schema
 * change, no accounting change). A kit (S4) drops straight in as a fully-priced section. Tenant-scoped.
 */
class ProjectQuoteService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /**
     * PURE section builder — turns kit ids (+ extras) into priced sections and a flat line list, with no
     * engine call and no writes. Returned as ['sections'=>[…], 'flat'=>[…]]. Testable in isolation.
     * @return array{sections:array<int,array<string,mixed>>, flat:array<int,array<string,mixed>>}
     */
    public function buildSections(array $d): array
    {
        $kitSvc = KitService::forTenant($this->tenant);
        $sections = [];
        $flat = [];

        foreach ($this->ids($d['kit_ids'] ?? ($d['kit_id'] ?? [])) as $kid) {
            $kit = Product::where('tenant_id', $this->tenant->id)->find($kid);
            if (! $kit) {
                continue;
            }
            $parts = $kitSvc->explode($kid, 1.0);
            if ($parts === []) {
                continue;
            }
            $lines = [];
            $subtotal = 0.0;
            foreach ($parts as $pt) {
                $qty = (float) $pt['quantity'];
                $price = (float) $pt['unit_price'];
                $lt = round($qty * $price, 2);
                $lines[] = ['name' => (string) $pt['name'], 'qty' => $qty, 'unit_price' => $price, 'line_total' => $lt];
                $flat[]  = ['name' => (string) $pt['name'], 'qty' => $qty, 'price' => $price];
                $subtotal += $lt;
            }
            $sections[] = ['title' => (string) $kit->name, 'lines' => $lines, 'subtotal' => round($subtotal, 2)];
        }

        // Optional extras (labour, delivery, etc.) as one section.
        $extras = is_array($d['extras'] ?? null) ? $d['extras'] : [];
        $exLines = [];
        $exSub = 0.0;
        foreach ($extras as $e) {
            $nm = trim((string) ($e['name'] ?? ''));
            if ($nm === '') {
                continue;
            }
            $qty = max(1, (int) ($e['qty'] ?? 1));
            $price = (float) ($e['price'] ?? 0);
            $lt = round($qty * $price, 2);
            $exLines[] = ['name' => $nm, 'qty' => $qty, 'unit_price' => $price, 'line_total' => $lt];
            $flat[]    = ['name' => $nm, 'qty' => $qty, 'price' => $price];
            $exSub += $lt;
        }
        if ($exLines !== []) {
            $sections[] = ['title' => (string) ($d['extras_title'] ?? 'Installation & extras'), 'lines' => $exLines, 'subtotal' => round($exSub, 2)];
        }

        return ['sections' => $sections, 'flat' => $flat];
    }

    /**
     * Build a project quote from one or more kits (each becomes a section of its component lines) plus
     * optional extra lines (labour, delivery, …) as their own section.
     * @return array<string,mixed>
     */
    public function fromKits(array $d): array
    {
        $built = $this->buildSections($d);
        $sections = $built['sections'];
        $flat = $built['flat'];

        if ($flat === []) {
            return ['ok' => false, 'error' => 'no_lines', 'message' => 'Pick at least one kit (with components) to build a project quote.'];
        }

        $name  = trim((string) ($d['customer_name'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', (string) ($d['customer_phone'] ?? ''));
        if (strlen($phone) < 7) {
            return ['ok' => false, 'error' => 'bad_customer', 'message' => 'Enter the customer name and a valid WhatsApp number.'];
        }

        // The FROZEN engine does the pricing / numbering / PDF / (optional) WhatsApp — untouched.
        $res = app(QuotationWriteService::class)->createFor(
            $this->tenant, $phone, $name, $flat, $d['currency'] ?? null, (bool) ($d['send'] ?? false)
        );
        if (! ($res['ok'] ?? false) || empty($res['id'])) {
            return ['ok' => false, 'error' => 'quote_failed', 'message' => (string) ($res['message'] ?? 'Could not create the quotation.')];
        }

        // Record the section grouping on the quotation (additive — meta only).
        $q = Quotation::where('tenant_id', $this->tenant->id)->find($res['id']);
        if ($q) {
            $meta = is_array($q->meta) ? $q->meta : [];
            $meta['project'] = [
                'is_project'     => true,
                'generated_from' => 'kits',
                'sections'       => $sections,
                'sections_total' => round(array_sum(array_map(fn ($s) => $s['subtotal'], $sections)), 2),
            ];
            $q->meta = $meta;
            $q->save();
        }

        return [
            'ok'       => true,
            'id'       => $res['id'],
            'quote_no' => (string) $res['quote_no'],
            'total'    => (float) ($res['total'] ?? 0),
            'currency' => (string) ($res['currency'] ?? ''),
            'sections' => count($sections),
            'sent'     => (bool) ($res['sent'] ?? false),
        ];
    }

    /** The section structure recorded on a quotation, or null for an ordinary quote. */
    public function sectionsFor(Quotation $q): ?array
    {
        $meta = is_array($q->meta) ? $q->meta : [];
        return $meta['project'] ?? null;
    }

    /** Normalise kit ids from an array, or a comma-list, or "id — label" combo values. */
    private function ids($v): array
    {
        if (is_array($v)) {
            return array_values(array_filter(array_map('intval', $v)));
        }
        $parts = preg_split('/[,;\n]+/', trim((string) $v)) ?: [];
        return array_values(array_filter(array_map(fn ($x) => (int) trim((string) $x), $parts)));
    }
}
