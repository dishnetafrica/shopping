<?php

namespace App\BOS\Projections;

/**
 * Canonical projection type 3 of 4. "Today" = an owner's activities due now, grouped by priority —
 * built ON TOP of Activity/Timeline (it is not a new data source, just a grouping). Concrete Today
 * views extend this base. A fifth projection *type* requires an ADR (ADR-014). Read-only.
 */
abstract class TodayProjection extends Projection
{
    // Concrete: Today = this owner's ActivityProjection, grouped by the smart-priority scorer.
}
