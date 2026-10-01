<?php

declare(strict_types=1);

namespace App\Email;

use App\Mail\StoredEmail;
use App\Models\EmailSend;
use App\Models\User;
use RuntimeException;

/**
 * Sending an email again.
 *
 * **Always a new row**, pointing at the original through `resent_from_id`. The
 * original keeps its failure, because that failure is the one fact anybody came
 * to the log to find. Overwriting it would erase the reason for the resend.
 *
 * Two modes, because they are genuinely different jobs:
 *
 * - **Verbatim** replays the stored body. Right for a receipt — history must not
 *   change because a template was edited since (§5.4's frozen snapshot rule).
 * - **Rebuilt** constructs the email again from current data. Right when the
 *   first attempt failed *because* something was wrong, and the only honest
 *   option for anything carrying a link that expires.
 *
 * Verbatim is refused where the email holds a signed, expiring URL. §9 puts every
 * file behind one, so a body stored in March contains a link that died in March —
 * replaying it would send a customer a dead page while telling the admin it
 * worked. That is worse than refusing.
 */
class Resend
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    /**
     * Send it again, built from current data.
     *
     * @param  string|null  $toEmail  a corrected address — the commonest real reason to resend
     */
    public function rebuilt(EmailSend $original, ?User $by = null, ?string $toEmail = null): EmailSend
    {
        $definition = $original->definition();

        if (! $original->mayResendRebuilt() || $definition?->mailable === null) {
            throw new RuntimeException($original->resendRefusal() ?? 'This email cannot be rebuilt.');
        }

        $mailable = $definition->mailable;
        $mail = $mailable::rebuild($original->payload ?? []);

        if ($mail === null) {
            /*
             * The order, certificate or subscriber this described has been
             * deleted. Not an exception in somebody's face — a row saying so,
             * which is both the honest answer and the one that leaves a trace of
             * having been asked.
             */
            $send = $this->dispatcher->send(
                new StoredEmail($original),
                resentFrom: $original,
                resentBy: $by,
                overrideTo: $toEmail,
            );

            $send->markSuppressed('The record this email described no longer exists, so it could not be rebuilt.');

            return $send;
        }

        return $this->dispatcher->send($mail, resentFrom: $original, resentBy: $by, overrideTo: $toEmail);
    }

    /** Send exactly what was stored. */
    public function verbatim(EmailSend $original, ?User $by = null, ?string $toEmail = null): EmailSend
    {
        if (! $original->mayResendVerbatim()) {
            throw new RuntimeException(
                $original->resendRefusal() ?? 'There is nothing stored to resend. Rebuild it instead.'
            );
        }

        return $this->dispatcher->send(
            new StoredEmail($original),
            resentFrom: $original,
            resentBy: $by,
            overrideTo: $toEmail,
        );
    }
}
