<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * The sections of the settings screen, and who may change each.
 *
 * The permission split is the point. "A webinar in this format gives you the
 * recording for twelve months" is marketing copy; the refund cooling-off window
 * is a money control that decides how long the business has to change its mind
 * before a refund leaves. Both were on one screen behind one permission, which
 * meant giving somebody the first also gave them the second.
 *
 * §8's roles carry this: Content Editor owns what the site says, Finance owns
 * what it charges, Super Admin owns the rest.
 */
enum SettingGroup: string
{
    case Business = 'business';
    case Brand = 'brand';
    case Commerce = 'commerce';
    case Payments = 'payments';
    case Catalogue = 'catalogue';
    case Certificates = 'certificates';
    case Email = 'email';
    case Integrations = 'integrations';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Brand => 'Brand',
            self::Commerce => 'Commerce',
            self::Payments => 'Payments',
            self::Catalogue => 'Catalogue',
            self::Certificates => 'Certificates',
            self::Email => 'Email & list',
            self::Integrations => 'Integrations',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Business => 'Who the business is, and where it is. Used on invoices, certificates and in the footer.',
            self::Brand => 'Logos and the promo bar.',
            self::Commerce => 'Order numbering and refunds.',
            self::Payments => 'Which checkout payment methods are switched on, and whether each gateway is connected.',
            self::Catalogue => 'The wording shown beside what you sell.',
            self::Certificates => 'When a certificate is issued automatically. Individual accrediting bodies can override these.',
            self::Email => 'What goes out, and who has agreed to receive it.',
            self::Integrations => 'Analytics, search console and live chat.',
        };
    }

    /**
     * The permission that lets somebody change this group.
     *
     * Reading the screen is `view_any_setting`; changing a group needs this on
     * top. A role without it still sees the tab, with its fields disabled —
     * hiding it would leave somebody unable to tell whether a value exists or
     * whether they simply cannot see it.
     */
    public function permission(): string
    {
        return match ($this) {
            // Marketing copy and imagery: the content editor's job.
            self::Brand, self::Catalogue => 'update_setting',
            // Money: order numbering appears on every invoice and the refund
            // window governs when money leaves. Which gateways take that money
            // belongs with the same permission.
            self::Commerce, self::Payments => 'manage_commerce_setting',
            // Accreditation rules. Getting these wrong issues credits that
            // should not exist — §12 has them as prescribed and not negotiable.
            self::Certificates => 'manage_certificate_setting',
            // Legal identity and anything that sends mail.
            self::Business, self::Email, self::Integrations => 'manage_business_setting',
        };
    }

    public function isEditableBy(?\App\Models\User $user): bool
    {
        return $user?->can($this->permission()) ?? false;
    }

    /** @return array<int, self> */
    public static function ordered(): array
    {
        return [self::Business, self::Brand, self::Commerce, self::Payments, self::Catalogue, self::Certificates, self::Email, self::Integrations];
    }
}
