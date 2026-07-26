<?php declare(strict_types=1);

namespace App\Services\Advisor;

use App\Models\AdvisorProject;
use App\Models\Product;
use App\Models\Tenant;

/**
 * Project Advisor — solution packages.
 *
 * Lists predefined packages for a profile and "starts" one: it creates a captured project from the
 * package's typical answers (so the deterministic engine sizes it) and generates a full proposal from
 * the package's equipment — one click to a tailorable proposal. Equipment SKUs that exist in the
 * catalogue are resolved to real products (real price + Product Specifications); the rest fall back to
 * the package's indicative price. Generic: a new industry registers its own package source.
 */
class PackageService
{
    /** profile key => callable returning the package list. Add industries here. */
    private const SOURCES = [
        'solar' => [SolarPackages::class, 'all'],
    ];

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    public function has(string $profile): bool
    {
        return isset(self::SOURCES[$profile]);
    }

    /** All packages for a profile (as a list). */
    public function list(string $profile): array
    {
        if (! $this->has($profile)) {
            return [];
        }
        [$cls, $m] = self::SOURCES[$profile];
        return array_values($cls::$m());
    }

    public function get(string $profile, string $key): ?array
    {
        foreach ($this->list($profile) as $p) {
            if (($p['key'] ?? '') === $key) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Start a project from a package: create the sized project, then generate its proposal.
     *
     * @param array $overrides optional ['name'?, 'location'?, 'customer_id'?, plus any answer overrides]
     * @return array{project:AdvisorProject, proposal:array, package:array}
     */
    public function start(string $profile, string $key, array $overrides = [], ?int $userId = null): array
    {
        $pkg = $this->get($profile, $key);
        if (! $pkg) {
            throw new \InvalidArgumentException("Unknown package: {$profile}/{$key}");
        }

        // Typical answers from the package, with caller overrides (e.g. real location) merged in.
        $answers = (array) ($pkg['answers'] ?? []);
        foreach ($overrides as $ok => $ov) {
            if (in_array($ok, ['name', 'customer_id'], true) || $ov === '' || $ov === null) {
                continue;
            }
            $answers[$ok] = $ov;
        }

        $project = ProjectAdvisorService::make()->save($this->tenant, [
            'profile'     => $profile,
            'name'        => ($n = trim((string) ($overrides['name'] ?? ''))) !== '' ? $n : (string) $pkg['name'],
            'customer_id' => $overrides['customer_id'] ?? null,
            'answers'     => $answers,
        ], $userId);

        $equipment = $this->resolveEquipmentRefs((array) ($pkg['equipment'] ?? []));
        $proposal  = ProposalService::forTenant($this->tenant)->generate($project, $equipment, []);

        return ['project' => $project->fresh(), 'proposal' => $proposal, 'package' => $pkg];
    }

    /**
     * Turn package equipment into refs for the ProposalService: a SKU present in the catalogue becomes a
     * product reference (real price + specs); otherwise the indicative name/price is kept.
     */
    public function resolveEquipmentRefs(array $equipment): array
    {
        $out = [];
        foreach ($equipment as $e) {
            $sku = trim((string) ($e['sku'] ?? ''));
            $qty = max(0.0, (float) ($e['qty'] ?? 1));
            $product = $sku !== ''
                ? Product::where('tenant_id', $this->tenant->id)->where('sku', $sku)->first()
                : null;
            if ($product) {
                $out[] = ['product_id' => $product->id, 'qty' => $qty];
            } else {
                $out[] = [
                    'name'       => (string) ($e['name'] ?? 'Item'),
                    'sku'        => $sku,
                    'qty'        => $qty,
                    'unit_price' => (float) ($e['unit_price'] ?? 0),
                ];
            }
        }
        return $out;
    }
}
