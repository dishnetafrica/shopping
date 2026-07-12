<?php declare(strict_types=1);

namespace App\Reports;

/**
 * Base for report definitions (Roadmap E7.1). Provides the default (no) filters and the `describe()`
 * serialisation the API uses to advertise a report's shape without running it.
 */
abstract class AbstractReport implements ReportDefinition
{
    public function filters(): array
    {
        return [];
    }

    /** The report's shape (key, title, columns, filters) for the API listing. */
    public function describe(): array
    {
        return [
            'key'     => $this->key(),
            'title'   => $this->title(),
            'columns' => array_map(fn (ReportColumn $c) => $c->toArray(), $this->columns()),
            'filters' => array_map(fn (ReportFilter $f) => $f->toArray(), $this->filters()),
        ];
    }
}
