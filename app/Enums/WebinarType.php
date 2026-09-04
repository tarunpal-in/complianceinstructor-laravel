<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a webinar has a live session (docs/webinars-module.md §3).
 *
 * Distinct from WebinarStatus, which is lifecycle. Type is what the thing *is*;
 * status is where it has got to. Deriving type from the offerings it happens to
 * sell would be fragile and would make every catalogue listing a join.
 *
 * Packages are deliberately NOT a third case. A package has no speaker, date,
 * duration or credits of its own, so it lives in its own table (§5.3) — folding
 * it in here would leave those columns meaningless on package rows and put a
 * type filter on every catalogue query.
 */
enum WebinarType: string
{
    case Live = 'live';
    case Recorded = 'recorded';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live Webinar',
            self::Recorded => 'Recorded',
        };
    }

    /**
     * Only a live webinar has a session to schedule. The admin form hides the
     * schedule tab for a recorded one, and validation stops requiring a date.
     */
    public function requiresSchedule(): bool
    {
        return $this === self::Live;
    }

    /**
     * Formats this type may sell.
     *
     * A recorded webinar must never sell the `live` format: there is no session
     * to attend, so the sale would take money for something that will never
     * happen. The publish gate enforces this.
     *
     * @return array<int, Format>
     */
    public function allowedFormats(): array
    {
        return match ($this) {
            self::Live => Format::cases(),
            self::Recorded => [Format::Recorded, Format::Transcript, Format::Download],
        };
    }

    public function allows(Format $format): bool
    {
        return in_array($format, $this->allowedFormats(), true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
