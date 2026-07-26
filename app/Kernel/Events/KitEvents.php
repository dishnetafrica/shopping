<?php declare(strict_types=1);

namespace App\Kernel\Events;

/**
 * The kit-lifecycle vocabulary (Asset Lifecycle · S4a). AUDIT events on the spine — the kit_components
 * rows are the source of truth; these just record that a bundle's composition changed, or (later) that
 * a batch was pre-assembled. Pure contract: names only, no I/O.
 */
final class KitEvents
{
    /** A kit's composition (its parts list) was set or changed. */
    public const DEFINED = 'KitDefined';

    /** A batch of a kit was pre-assembled from its parts (preassembly slice — not wired yet). */
    public const ASSEMBLED = 'KitAssembled';
}
