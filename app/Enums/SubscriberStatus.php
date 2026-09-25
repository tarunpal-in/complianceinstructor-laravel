<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether this address may be sent marketing email.
 *
 * The three suppressed states are kept apart although all three stop the mail,
 * because they mean different things and a business gets asked about them
 * differently. **Unsubscribed** is somebody exercising a right; **Bounced** is
 * a mailbox that does not exist; **Complained** is somebody telling their mail
 * provider that this was spam — the one that damages a sending domain, and the
 * one that must never be undone by a form submission.
 *
 * Nothing here is ever deleted to stop mail going out. Deleting the row deletes
 * the record of the refusal, and the next import puts them straight back on the
 * list — see the table comment. Suppression *is* the row.
 */
enum SubscriberStatus: string
{
    /** Signed up, not yet confirmed. Not mailed. */
    case Pending = 'pending';

    /** On the list. */
    case Subscribed = 'subscribed';

    /** Asked to be taken off. */
    case Unsubscribed = 'unsubscribed';

    /** The mailbox rejected us permanently. */
    case Bounced = 'bounced';

    /** Reported as spam. */
    case Complained = 'complained';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to confirm',
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed',
            self::Bounced => 'Bouncing',
            self::Complained => 'Reported as spam',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Subscribed => 'success',
            self::Unsubscribed => 'gray',
            self::Bounced, self::Complained => 'danger',
        };
    }

    /** Whether a campaign may include this address. The only state that says yes. */
    public function isMailable(): bool
    {
        return $this === self::Subscribed;
    }

    /** Whether this address is being held off the list on purpose. */
    public function isSuppressed(): bool
    {
        return $this === self::Unsubscribed
            || $this === self::Bounced
            || $this === self::Complained;
    }

    /**
     * Whether somebody filling in the signup form again may put this address
     * back on the list.
     *
     * Unsubscribing and then signing up again is a person changing their mind,
     * and refusing it would be obstructive. A spam complaint is not: they told
     * a mail provider we were spam, and a form field is nowhere near enough
     * evidence to overturn that. Staff can, deliberately, on the record.
     */
    public function mayResubscribeThemselves(): bool
    {
        return $this !== self::Complained;
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
