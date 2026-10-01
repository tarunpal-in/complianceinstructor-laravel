<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What became of one email.
 *
 * **`Sent` means the SMTP server accepted it. It does not mean it arrived.**
 * That distinction is the whole reason this enum has a `label()` rather than
 * letting a screen title-case the value: a green "Delivered" badge the system
 * cannot verify is worse than no badge at all, because staff will quote it to a
 * customer who is telling them the truth about not receiving anything.
 *
 * The client sends through a consumer Gmail account, which has no webhook and no
 * admin log search, so nothing will ever report delivery, bounce or complaint.
 * Those states are deliberately absent instead of present-and-never-used — §14
 * #12 is about loose strings, and a status nothing can set is the same trap in
 * a different shape.
 */
enum EmailStatus: string
{
    /** On the queue, waiting for a worker. */
    case Queued = 'queued';

    /** A worker has it. */
    case Sending = 'sending';

    /** Held back — the daily allowance is spent. It will go after midnight. */
    case Deferred = 'deferred';

    /** Gmail accepted it. */
    case Sent = 'sent';

    /** Rejected by the server, or the job gave up. */
    case Failed = 'failed';

    /** Not sent on purpose: suppressed address, email switched off, or halted. */
    case Suppressed = 'suppressed';

    /** A sequence step that stopped mattering before it ran. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Deferred => 'Waiting for allowance',
            // Not "Delivered". See the class comment.
            self::Sent => 'Handed to Gmail',
            self::Failed => 'Failed',
            self::Suppressed => 'Not sent',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'info',
            self::Deferred => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
            self::Suppressed, self::Cancelled => 'gray',
        };
    }

    /** Whether anything further will happen to this row on its own. */
    public function isSettled(): bool
    {
        return $this !== self::Queued
            && $this !== self::Sending
            && $this !== self::Deferred;
    }

    /**
     * Whether resending is a sensible offer.
     *
     * Not for a row still in flight — a second copy of an email that is about to
     * send is the mistake this guards against, and it is an easy one to make
     * while watching a queue that looks stuck.
     */
    public function mayResend(): bool
    {
        return $this->isSettled();
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
