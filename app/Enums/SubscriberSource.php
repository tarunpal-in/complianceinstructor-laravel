<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where somebody agreed to be emailed.
 *
 * This is the provenance of a consent, not marketing attribution — which is why
 * it is a short enum and not the campaign fields that were cut from
 * `inquiries`. The question it answers is the one a compliance-training company
 * should expect to be asked about its own mailing list: *where did this person
 * say yes, and what were they looking at when they did?*
 */
enum SubscriberSource: string
{
    /** The signup box in the site footer. */
    case Footer = 'footer';

    /** Ticked a box while buying something. */
    case Checkout = 'checkout';

    /** Signed up from an article. */
    case Blog = 'blog';

    /** Ticked "keep me updated" on the contact form. */
    case Contact = 'contact';

    /** Loaded from a list — a conference, a spreadsheet, another system. */
    case Import = 'import';

    /** Added by staff, from a card or a phone call. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Footer => 'Site footer',
            self::Checkout => 'At checkout',
            self::Blog => 'From an article',
            self::Contact => 'Contact form',
            self::Import => 'Imported list',
            self::Manual => 'Added by staff',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Footer, self::Blog => 'info',
            self::Checkout => 'success',
            self::Contact => 'warning',
            self::Import, self::Manual => 'gray',
        };
    }

    /**
     * Whether the person themselves asked for this.
     *
     * An imported list agreed to nothing here, whatever the spreadsheet said,
     * and a business emailing it should be able to see that at a glance rather
     * than discovering it after a complaint.
     */
    public function isSelfSubmitted(): bool
    {
        return $this !== self::Import && $this !== self::Manual;
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
