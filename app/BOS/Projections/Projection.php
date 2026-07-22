<?php

namespace App\BOS\Projections;

/**
 * A projection is a read-only view built from objects (current state) + events (history). Projections
 * are disposable and rebuildable (R23); they NEVER persist to a business object. The fitness tests
 * assert that nothing in this namespace performs a persistence write.
 */
abstract class Projection
{
    /** Build and return the view (array/collection). Pure read — no writes to source objects. */
    abstract public function build(): mixed;
}
