<?php

declare(strict_types=1);

namespace App\Reports;

use App\Models\Webinar;

/**
 * One webinar's performance: what it sold, whether people watched it, and
 * whether it produced the credits they bought it for.
 *
 * A class rather than an array shape, for the same reason as ReportRow:
 * Collection is invariant in PHPStan, so a collection of one shape is not
 * assignable to a collection of an identical-looking one, and every method
 * passing these around would need its own declared shape.
 */
final class WebinarRow
{
    public function __construct(
        public readonly Webinar $webinar,
        public readonly int $seats,
        public readonly int $revenueCents,
        public readonly int $views,
        public readonly int $completed,
        public readonly int $certificates,
    ) {}

    /**
     * Views finished over views started, as a percentage.
     *
     * Null with no views rather than zero: somebody who bought a recording and
     * never opened it has not failed to finish it, and "0% completion" on
     * something nobody started is a statement about the content rather than
     * about the absence of data.
     */
    public function completion(): ?float
    {
        if ($this->views === 0) {
            return null;
        }

        return round(($this->completed / $this->views) * 100, 1);
    }

    public function describeCompletion(): string
    {
        $completion = $this->completion();

        return $completion === null ? 'No views' : $completion.'%';
    }

    /** Whether anything at all happened to this webinar in scope. */
    public function isInteresting(): bool
    {
        return $this->seats > 0 || $this->views > 0 || $this->certificates > 0;
    }
}
