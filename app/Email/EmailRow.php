<?php

declare(strict_types=1);

namespace App\Email;

use Illuminate\Support\Carbon;

/**
 * One line on the Emails screen.
 *
 * A typed object rather than an array shape, for the reason the report rows are:
 * PHPStan's Collection template is not covariant, so an array shape never
 * composes through `mapWithKeys` without the return type drifting out of step
 * with what is actually built.
 */
final class EmailRow
{
    public function __construct(
        public readonly EmailDefinition $email,
        /** How many went out in the last 30 days. */
        public readonly int $sent,
        public readonly ?Carbon $lastSent,
        public readonly bool $enabled,
        /** Whether the screen draws a switch, as opposed to a lock or a note. */
        public readonly bool $switchable,
    ) {}
}
