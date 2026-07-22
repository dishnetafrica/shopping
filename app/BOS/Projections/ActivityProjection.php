<?php

namespace App\BOS\Projections;

/**
 * Canonical projection type 2 of 4 (Timeline · Activity · Today · Metrics). "Activity" = open work
 * for an owner (what must be done), derived from objects + events. Concrete activity views extend
 * this base — a FIFTH projection *type* requires an ADR (ADR-014). Read-only, rebuildable.
 */
abstract class ActivityProjection extends Projection
{
    // Concrete activity projections (collections, follow-ups, deliveries…) implement build().
}
