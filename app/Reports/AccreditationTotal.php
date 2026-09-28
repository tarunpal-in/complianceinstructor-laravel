<?php

declare(strict_types=1);

namespace App\Reports;

/**
 * One accrediting body's figures for a period.
 *
 * `credits` is a decimal string rather than a float. Credits are stored as
 * decimal(4,2) because half credits are the whole point — §CreditHours makes 90
 * minutes 1.5 — and a body's submission is checked against what it was told.
 * Float arithmetic on a number somebody will reconcile is how "1.50" becomes
 * "1.4999999".
 */
final class AccreditationTotal
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $certificates,
        public readonly string $credits,
        public readonly int $learners,
    ) {}
}
