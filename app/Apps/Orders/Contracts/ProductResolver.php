<?php
namespace App\Apps\Orders\Contracts;

use App\Apps\Orders\Dto\CartLine;
use App\Apps\Orders\Resolve\ProductResolution;

/**
 * Resolves an extracted product line (name + weight) to a concrete catalogue line by ranking the
 * real Product Master. report() returns the full ranked decision (auto | clarify | none) for the
 * Order Engine to act on; resolve() is the convenience that returns the auto-resolved line.
 */
interface ProductResolver
{
    /** @param array{category?:string,recent?:string[]} $context */
    public function report(int $tenantId, CartLine $line, array $context = []): ProductResolution;

    public function resolve(int $tenantId, CartLine $line): CartLine;
}
