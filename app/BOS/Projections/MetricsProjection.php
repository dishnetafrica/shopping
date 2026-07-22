<?php

namespace App\BOS\Projections;

/**
 * Canonical projection type 4 of 4. "Metrics" = aggregations over events (Insights, dashboards,
 * KPIs). Every figure traces to a real event/field — no invented data (R23, doc 4). Concrete metric
 * views extend this base. A fifth projection *type* requires an ADR (ADR-014). Read-only.
 */
abstract class MetricsProjection extends Projection
{
    // Concrete: sales-today, collections, overdue, top-customers, profit — all event aggregations.
}
