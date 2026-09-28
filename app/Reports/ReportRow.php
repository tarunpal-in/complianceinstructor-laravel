<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * One line of a report: something, and how much money it was.
 *
 * A small class rather than an array shape. Every breakdown returns
 * `Collection<int, ReportRow>`, which means they are interchangeable — a page
 * can render any of them with one loop, and static analysis can check it.
 * Array shapes could not do that: `Collection` is invariant in PHPStan, so a
 * collection of `{label, cents}` is not assignable to a collection of
 * `{label, cents, uses}`, and every breakdown would have needed its own
 * rendering path.
 *
 * `key` and `note` are the two places a breakdown carries something extra —
 * a bucket identifier for the over-time series, and "3 uses" against a coupon.
 */
final class ReportRow
{
    public function __construct(
        public readonly string $label,
        public readonly int $cents,
        public readonly ?string $key = null,
        public readonly ?string $note = null,
    ) {}

    /** Share of the largest row, for the bar behind the figure. */
    public function shareOf(int $largest): float
    {
        if ($largest <= 0) {
            return 0.0;
        }

        return max(0.0, round(($this->cents / $largest) * 100, 1));
    }
}
