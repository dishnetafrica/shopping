<?php declare(strict_types=1);

namespace App\Services\Advisor;

use App\Models\AdvisorProject;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalog\ProductSpecService;

/**
 * Project Advisor — proposal generation service.
 *
 * Resolves an equipment list (product ids → names, prices, warranty and Product Specifications), picks
 * the segment template, hands everything to the pure {@see ProposalBuilder}, and stores the assembled
 * proposal on the project. Reuse of the quotation + PDF engine happens at the "send" step via
 * {@see ProposalBuilder::toQuotationLines()} — the commercial figures here are computed the same way, so
 * they stay consistent.
 */
class ProposalService
{
    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /**
     * Generate and persist a proposal for a captured project.
     *
     * @param array $equipmentRefs each: ['product_id'=>int, 'qty'=>float] OR an explicit
     *                             ['name','sku','qty','unit_price','warranty','specs'=>[...]]
     * @param array $opts          ['currency'?, 'install_fee'?, 'vat_pct'?]
     */
    public function generate(AdvisorProject $project, array $equipmentRefs = [], array $opts = []): array
    {
        $equipment = $this->resolveEquipment($equipmentRefs);

        $opts = array_merge([
            'currency' => (string) ($this->tenant->setting('currency', 'UGX') ?: 'UGX'),
        ], array_filter($opts, fn ($v) => $v !== null && $v !== ''));

        $segment  = (string) (($project->answers['customer_type'] ?? ''));
        $template = ProposalTemplate::for($segment);

        $proposal = ProposalBuilder::build([
            'name'    => (string) $project->name,
            'profile' => (string) $project->profile,
            'answers' => (array) $project->answers,
            'sizing'  => (array) $project->sizing,
        ], $equipment, $template, $opts);

        $project->proposal = $proposal;
        $project->status   = 'proposed';
        $project->save();

        return $proposal;
    }

    /** Turn equipment references into resolved line items (name, sku, price, warranty, specs). */
    public function resolveEquipment(array $refs): array
    {
        $out = [];
        $specSvc = ProductSpecService::forTenant($this->tenant);
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $qty = max(0.0, (float) ($ref['qty'] ?? 1));

            $pid = (int) ($ref['product_id'] ?? 0);
            if ($pid > 0 && $p = Product::where('tenant_id', $this->tenant->id)->find($pid)) {
                $price = ($ref['unit_price'] ?? '') !== '' ? (float) $ref['unit_price'] : (float) ($p->price ?? $p->base_price ?? 0);
                $out[] = [
                    'name'       => (string) $p->name,
                    'sku'        => (string) ($p->sku ?? ''),
                    'qty'        => $qty,
                    'unit_price' => $price,
                    'warranty'   => self::warrantyText((int) $p->warranty_manufacturer_months, (int) $p->warranty_company_months),
                    'specs'      => $specSvc->specsFor($pid),
                ];
                continue;
            }

            // Explicit (non-catalogue) line item.
            $name = trim((string) ($ref['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'       => $name,
                'sku'        => (string) ($ref['sku'] ?? ''),
                'qty'        => $qty,
                'unit_price' => (float) ($ref['unit_price'] ?? 0),
                'warranty'   => (string) ($ref['warranty'] ?? ''),
                'specs'      => is_array($ref['specs'] ?? null) ? $ref['specs'] : [],
            ];
        }
        return $out;
    }

    private static function warrantyText(int $mfr, int $company): string
    {
        $parts = [];
        if ($mfr > 0)     $parts[] = $mfr . '-month manufacturer warranty';
        if ($company > 0) $parts[] = $company . '-month supplier warranty';
        return implode(' · ', $parts);
    }
}
