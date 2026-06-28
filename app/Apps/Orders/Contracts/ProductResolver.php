<?php
namespace App\Apps\Orders\Contracts;

use App\Apps\Orders\Dto\CartLine;

/**
 * Resolves an extracted product line (name + weight + qty) to a concrete catalogue line —
 * product_id, variant_id and unit_price — by consulting OKE / the catalogue. This is the seam
 * where the Order Engine asks OKE "what does the business sell and at what price?". The weight-
 * aware price lookup here is what makes the old "everything is 1kg" bug structurally impossible.
 * Concrete implementation (over CatalogueMatcher + weight pricing) lands in the wiring drop.
 */
interface ProductResolver
{
    public function resolve(int $tenantId, CartLine $line): CartLine;
}
