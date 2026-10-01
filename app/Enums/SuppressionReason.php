<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an address is being held back.
 *
 * **Every row on this table is entered by a person**, and will be until there is
 * a provider that reports bounces. A consumer Gmail account has no webhook and
 * no admin log search, so the only bounce signal is the bounce message arriving
 * in the sending mailbox — which is exactly why the sending address must be one
 * somebody reads, and not a `noreply@`.
 *
 * Kept apart from `subscribers`, which is the marketing list, because these are
 * different decisions. "Stop marketing to this address" and "stop sending this
 * address its invoice" must never be the same switch, and an address can bounce
 * that was never on the list at all.
 */
enum SuppressionReason: string
{
    /** The mailbox rejected us permanently. Read off a bounce message. */
    case Bounced = 'bounced';

    /** Reported as spam. The one that costs a sending reputation. */
    case Complained = 'complained';

    /** Not a real mailbox — a typo, a role address that was never read. */
    case Invalid = 'invalid';

    /** Somebody asked, outside the mailing list. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Bounced => 'Bouncing',
            self::Complained => 'Reported as spam',
            self::Invalid => 'Not a real address',
            self::Manual => 'Held back by staff',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Bounced, self::Complained => 'danger',
            self::Invalid => 'warning',
            self::Manual => 'gray',
        };
    }

    /**
     * Whether this reason stops an email somebody is owed.
     *
     * **Only a dead mailbox does.** Sending an invoice to an address that does
     * not exist achieves nothing and damages a sending reputation; withholding
     * one because somebody reported a newsletter as spam would be us deciding
     * they may not have the receipt for what they paid for. They can complain
     * about the marketing and still be owed their certificate.
     */
    public function blocksRequiredMail(): bool
    {
        return $this === self::Bounced || $this === self::Invalid;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
