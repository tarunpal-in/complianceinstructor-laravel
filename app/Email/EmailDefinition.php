<?php

declare(strict_types=1);

namespace App\Email;

use App\Enums\EmailCategory;
use App\Enums\EmailTier;
use App\Models\Setting;

/**
 * One email this application can send, declared once.
 *
 * The same shape of thing as SettingDefinition, and for the same reason: the
 * alternative is a fact about an email living in four places — the class that
 * sends it, the screen that toggles it, the key that stores the toggle, and
 * whatever note says which of them a customer may decline. Four copies is three
 * too many, and the one that drifts is always the one that decides whether
 * somebody gets their certificate.
 */
final class EmailDefinition
{
    public function __construct(
        /** Stable identifier. Stored on every `email_sends` row, so never renamed lightly. */
        public readonly string $key,
        /** What a person calls it on the Emails screen. */
        public readonly string $name,
        public readonly EmailCategory $category,
        public readonly EmailTier $tier,
        /** What causes it, in a sentence. Shown on the screen; this is documentation that cannot go stale unread. */
        public readonly string $trigger,
        /** The Mailable. Null while the trigger it needs has not been built. */
        public readonly ?string $mailable = null,
        /**
         * Why this one has no off switch.
         *
         * Required only, and required emails must have it — the catalogue test
         * fails without one. A lock with no reason beside it reads as an
         * oversight, and the client is owed the argument rather than the wall.
         */
        public readonly ?string $lockReason = null,
        /**
         * Whether the body contains a signed, expiring link.
         *
         * Decides whether "resend exactly as it was" is offered. §9 puts every
         * file behind a signed expiring URL, so a body stored in March holds a
         * link that died in March — replaying it would send a customer a broken
         * page and look like the system working.
         */
        public readonly bool $hasExpiringLinks = false,
        /** Not yet buildable, and why — shown on the screen rather than silently absent. */
        public readonly ?string $blockedBy = null,
        /**
         * Why the resend button is withheld for this one.
         *
         * A password reset carries a single-use token that has been consumed or
         * expired, so resending it sends a dead link while telling the admin it
         * worked. The honest action there is to issue a new reset, and the
         * screen says so instead of offering a button that lies.
         */
        public readonly ?string $notResendableReason = null,
        /**
         * Whether the recipient is owed this even after a spam complaint.
         *
         * Separate from `tier`, which answers a different question. `tier` decides
         * whether an *admin* has a switch; this decides whether somebody who told
         * their provider we were spam still gets it. Conflating the two made the
         * mailing-list confirmation — Required, because double opt-in breaks
         * without it — exempt from spam complaints as a side effect, which is the
         * opposite of what anybody wanted.
         *
         * Null means "whatever the tier implies", which is right for all but one.
         */
        public readonly ?bool $owedToRecipient = null,
    ) {}

    public function isResendable(): bool
    {
        return $this->notResendableReason === null;
    }

    /**
     * Whether this still goes out to an address that reported us as spam.
     *
     * A dead mailbox blocks everything — see SuppressionReason. This is only
     * about the complaint case: somebody can complain about the marketing and
     * still be owed their receipt, their reset link and their certificate.
     */
    public function isOwedToRecipient(): bool
    {
        return $this->owedToRecipient ?? ! $this->tier->isSwitchable();
    }

    /** Whether the trigger behind this exists yet. */
    public function isBuilt(): bool
    {
        return $this->mailable !== null;
    }

    /** The setting key holding its on/off state. */
    public function settingKey(): string
    {
        return 'email.enabled.'.$this->key;
    }

    /**
     * Whether this email is switched on.
     *
     * A required email is always on and has no key to read — reading one would
     * mean a row existed that could be set to false, which is the whole thing
     * this tier exists to prevent.
     */
    public function isEnabled(): bool
    {
        // An unbuilt email has no key either — SettingRegistry only generates one
        // for an email that can actually send, so reading it would trip the
        // registry's own guard against settings nobody can change.
        if (! $this->tier->isSwitchable() || ! $this->isBuilt()) {
            return $this->tier->defaultEnabled() || ! $this->tier->isSwitchable();
        }

        return (bool) Setting::get($this->settingKey(), $this->tier->defaultEnabled());
    }

    /** Whether a recipient may decline it. Required mail ignores preferences. */
    public function mayBeDeclinedByRecipient(): bool
    {
        return $this->tier->isSwitchable() && $this->category->mayBeDeclinedByRecipient();
    }

    /**
     * Whether the footer carries an unsubscribe link.
     *
     * Marketing needs one by law — except where the email is the thing that
     * creates the subscription. Offering somebody a way out of the confirmation
     * that would put them on the list is an invitation to a dead end.
     */
    public function needsUnsubscribeLink(): bool
    {
        return $this->category->needsUnsubscribeLink() && $this->tier->isSwitchable();
    }

    public function queue(): string
    {
        return $this->category->queue();
    }

    public function isBulk(): bool
    {
        return $this->category->isBulk();
    }

    /** How many days a rendered body of this kind is kept. */
    public function retentionDays(): int
    {
        $days = Setting::declared($this->category->retentionSettingKey());

        return is_numeric($days) ? (int) $days : 90;
    }
}
