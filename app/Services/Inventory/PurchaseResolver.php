<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\Tenant;

/**
 * v4.65 — turning what somebody typed on a purchase into a product id.
 *
 * One resolver, used by the Buy stock form and by `purchase:relink`, so a name cannot link on one
 * path and fail on the other — which would be the most confusing possible outcome, and was very
 * nearly shipped.
 *
 * The catalogue is loaded ONCE per resolver, because a five-line purchase should not be five
 * queries and a relink over four hundred lines should not be four hundred.
 *
 * A name held by two products resolves to NEITHER. Ambiguous is not the same as unmatched: guessing
 * between two products is the one outcome worse than leaving the line alone, because it is invisible.
 */
final class PurchaseResolver
{
    /** @var array<string,int|false> normalised name/alias => product id, or false when ambiguous */
    private array $map = [];

    private function __construct(Tenant $t)
    {
        try {
            Product::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->get(['id', 'name', 'keywords'])
                ->each(function ($p) {
                    $keys = array_merge(
                        [PurchaseNameMatch::key((string) $p->name)],
                        PurchaseNameMatch::aliases((string) $p->keywords)
                    );
                    foreach (array_filter($keys) as $k) {
                        // Already claimed by another product → nobody gets it.
                        $this->map[$k] = array_key_exists($k, $this->map) && $this->map[$k] !== (int) $p->id
                            ? false
                            : (int) $p->id;
                    }
                });
        } catch (\Throwable $e) {
            $this->map = [];
        }
    }

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    /** The product this name means, or null when nothing or more than one thing does. */
    public function find(?string $name): ?int
    {
        $k = PurchaseNameMatch::key($name);
        if ($k === '' || ! array_key_exists($k, $this->map)) {
            return null;
        }

        return $this->map[$k] === false ? null : (int) $this->map[$k];
    }

    /** Is this name claimed by more than one product? Worth telling a person about. */
    public function isAmbiguous(?string $name): bool
    {
        $k = PurchaseNameMatch::key($name);

        return array_key_exists($k, $this->map) && $this->map[$k] === false;
    }
}
