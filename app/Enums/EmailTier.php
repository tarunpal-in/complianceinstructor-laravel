<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an email can be switched off, and by whom.
 *
 * The dangerous version of "let the client enable and disable emails" is a
 * screen of 34 identical toggles. §14 #6 is a customer who "was charged and
 * received nothing, with no record written" — and a client who turns off order
 * confirmations to reduce volume reproduces that defect from the admin panel,
 * believing they are tidying.
 *
 * So the decision lives on the email, not on the screen. A Required email has no
 * toggle at all — not a toggle set to on — and the screen shows a lock with the
 * reason beside it. Showing them rather than hiding them is deliberate: if the
 * client disagrees it should be a conversation, not a limitation they discover
 * in month three.
 */
enum EmailTier: string
{
    /**
     * No toggle exists. Somebody paid, or asked, or lost access.
     */
    case Required = 'required';

    /** A toggle, on by default. */
    case Optional = 'optional';

    /** A toggle, off by default. Somebody has to want it. */
    case OptIn = 'opt_in';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Always sent',
            self::Optional => 'On by default',
            self::OptIn => 'Off by default',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Required => 'danger',
            self::Optional => 'success',
            self::OptIn => 'gray',
        };
    }

    /** Whether the screen draws a switch for this one. */
    public function isSwitchable(): bool
    {
        return $this !== self::Required;
    }

    /** What the switch reads when nobody has touched it. */
    public function defaultEnabled(): bool
    {
        return $this !== self::OptIn;
    }
}
