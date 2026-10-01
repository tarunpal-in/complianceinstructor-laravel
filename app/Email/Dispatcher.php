<?php

declare(strict_types=1);

namespace App\Email;

use App\Enums\EmailStatus;
use App\Jobs\SendEmail;
use App\Mail\LoggedMail;
use App\Models\EmailSend;
use App\Models\EmailSuppression;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only way this application sends an email.
 *
 * Every send writes its row **before** anything reaches a mail server, so a
 * refusal, a failure and a deferral all leave evidence. §14 #6 is the
 * predecessor's worst defect — a customer charged who "received nothing, with no
 * record written" — and the half of that sentence people skip is the second half.
 * Silence that looks like success is what this removes.
 *
 * `phpstan.neon` forbids `Mail::to`, `Mail::send`, `Mail::queue`,
 * `Notification::send` and `->notify()` anywhere else. Without that ban this
 * class is a convention, and the first person in a hurry sends straight from an
 * action and puts a hole in the log that nobody notices until a customer says
 * they never got it.
 *
 * **The order the guards run in is the contract:**
 *
 *   kill switch → admin toggle → suppression → recipient preference → allowance
 *
 * A required email ignores the recipient's preference, because somebody cannot
 * opt out of being told their password changed. Nothing ignores the kill switch.
 */
class Dispatcher
{
    /**
     * Send one email, recording it either way.
     *
     * Never throws for an ordinary refusal. A suppressed address or a switched-off
     * email is a normal outcome with a row explaining itself, not an exception
     * for a caller to handle — and a caller who had to handle it would be a
     * second place deciding whether an email may go out.
     */
    public function send(
        LoggedMail $mail,
        ?EmailSend $resentFrom = null,
        ?User $resentBy = null,
        /**
         * A corrected address, used only by a resend.
         *
         * The commonest real reason to resend is a mistyped address, and a resend
         * to the same wrong address is useless. The original row keeps the
         * original address, so what went where is still readable.
         */
        ?string $overrideTo = null,
    ): EmailSend {
        $definition = $mail->definition();
        $recipient = $overrideTo ?? $mail->recipientEmail();
        $send = $this->record($mail, $definition, $recipient, $resentFrom, $resentBy);

        $refusal = $this->refusalReason($mail, $definition, $recipient);

        if ($refusal !== null) {
            $send->markSuppressed($refusal);

            return $send;
        }

        if (! SendBudget::allows($definition)) {
            $send->markDeferred(SendBudget::exhaustedReason($definition));

            return $send;
        }

        return $this->queue($send, $mail, $definition);
    }

    /**
     * Put a deferred row back on the queue.
     *
     * Used by the release sweep. Re-checks the allowance rather than trusting the
     * sweep's own count, because two sweeps and a live checkout can all be
     * spending the same allowance at once.
     */
    public function release(EmailSend $send): bool
    {
        $definition = $send->definition();

        if ($definition === null || ! $definition->isBuilt()) {
            return false;
        }

        if (! SendBudget::allows($definition)) {
            return false;
        }

        $mailable = $definition->mailable;
        $mail = $mailable::rebuild($send->payload ?? []);

        if ($mail === null) {
            $send->markSuppressed('The record this email described no longer exists.');

            return false;
        }

        $this->queue($send, $mail, $definition);

        return true;
    }

    /**
     * The row, written before anything else happens.
     *
     * The body is rendered here rather than in the job, so what is stored is what
     * the template said at the moment of sending — the `billing_snapshot` rule
     * from §5.4. A template edited next week must not change what the log says
     * went out last week.
     */
    private function record(
        LoggedMail $mail,
        EmailDefinition $definition,
        string $recipient,
        ?EmailSend $resentFrom,
        ?User $resentBy,
    ): EmailSend {
        $related = $mail->relatedRecord();

        return EmailSend::create([
            'uuid' => (string) Str::uuid(),
            'email_key' => $definition->key,
            'category' => $definition->category,
            'mailable' => $mail::class,
            'payload' => $mail->logPayload(),
            'to_email' => Str::lower(trim($recipient)),
            // Dropped when the address was corrected: the name belonged to
            // whoever the first attempt was aimed at, and keeping it would
            // address a stranger by somebody else's name.
            'to_name' => $recipient === $mail->recipientEmail() ? $mail->recipientName() : null,
            'bcc' => self::monitoringBcc(),
            'reply_to' => LoggedMail::replyToAddress()?->address,
            'subject' => $this->subjectOf($mail),
            'body_html' => $this->renderOrNull($mail),
            'user_id' => $mail->recipientUser()?->getKey(),
            'subscriber_id' => $mail->recipientSubscriber()?->getKey(),
            'related_type' => $related === null ? null : $related::class,
            'related_id' => $related?->getKey(),
            'status' => EmailStatus::Queued,
            'queued_at' => now(),
            'resent_from_id' => $resentFrom?->getKey(),
            'resent_by_id' => $resentBy?->getKey(),
        ]);
    }

    /**
     * Why this will not be sent, or null to send it.
     *
     * Each branch returns a sentence rather than a code, because the sentence is
     * what somebody reads in the log when asked why a customer did not get their
     * email. A status of "suppressed" on its own answers nothing.
     */
    private function refusalReason(LoggedMail $mail, EmailDefinition $definition, string $recipient): ?string
    {
        if (self::isHalted()) {
            return 'All sending is halted. Turn it back on from the Emails screen.';
        }

        if (! $definition->isEnabled()) {
            return "\"{$definition->name}\" is switched off on the Emails screen.";
        }

        if (EmailSuppression::blocks($recipient, $definition->isOwedToRecipient())) {
            $suppression = EmailSuppression::activeFor($recipient);

            return 'This address is held back: '
                .($suppression?->reason->label() ?? 'suppressed')
                .($suppression?->note === null ? '.' : " ({$suppression->note}).");
        }

        if ($definition->mayBeDeclinedByRecipient() && $this->recipientDeclined($mail, $definition)) {
            return "The recipient has turned off \"{$definition->category->label()}\".";
        }

        return $this->blockedByAllowList($recipient);
    }

    /**
     * Whether the person has turned this category off for themselves.
     *
     * Only consulted for an email they are allowed to decline — the check above
     * gates this one, so a required email never reaches here and a preference
     * cannot accidentally suppress a receipt.
     */
    private function recipientDeclined(LoggedMail $mail, EmailDefinition $definition): bool
    {
        $user = $mail->recipientUser();

        if ($user === null) {
            return false;
        }

        return ! $user->acceptsEmailCategory($definition->category);
    }

    /**
     * Keep seeded and staging data away from real inboxes.
     *
     * The seeder writes realistic customer addresses, and a mistake on staging
     * mails strangers on the client's behalf. Recorded as suppressed with the
     * reason rather than silently dropped, so staging still shows what *would*
     * have gone out — a silent drop would make the log a different shape on
     * staging than in production, which is the worst way to test a log.
     */
    private function blockedByAllowList(string $email): ?string
    {
        if (app()->isProduction()) {
            return null;
        }

        $raw = Setting::declared('email.allow_list');
        $patterns = collect(is_string($raw) ? preg_split('/[\s,;]+/', $raw) ?: [] : [])
            ->filter(fn (string $pattern): bool => trim($pattern) !== '')
            ->map(fn (string $pattern): string => Str::lower(trim($pattern)))
            ->all();

        if ($patterns === []) {
            return null;
        }

        $email = Str::lower(trim($email));

        foreach ($patterns as $pattern) {
            if ($email === $pattern || Str::endsWith($email, $pattern)) {
                return null;
            }
        }

        return 'Not on the allow list for this environment, so it was not sent.';
    }

    private function queue(EmailSend $send, LoggedMail $mail, EmailDefinition $definition): EmailSend
    {
        $send->forceFill([
            'status' => EmailStatus::Queued,
            'queued_at' => now(),
            'outcome_note' => null,
        ])->save();

        SendEmail::dispatch($send->getKey(), $mail)->onQueue($definition->queue());

        /*
         * Refreshed before handing it back. The job loads its own instance of
         * this row — it has to, since it may run in another process minutes later
         * — so on a synchronous queue the copy held here is already stale by the
         * time dispatch returns, and a caller reading `status` would be told
         * "queued" about an email that has been sent.
         */
        return $send->refresh();
    }

    /**
     * The rendered body, or null if the template is broken.
     *
     * A view that will not render is a real possibility and must not take down
     * the thing that triggered the email — an order being placed, a password
     * being reset. The row still exists, the send still happens, and the log
     * shows a body nobody could store.
     */
    private function renderOrNull(LoggedMail $mail): ?string
    {
        try {
            return $mail->render();
        } catch (Throwable $exception) {
            Log::warning('An email body could not be rendered for the log.', [
                'mailable' => $mail::class,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function subjectOf(LoggedMail $mail): string
    {
        try {
            return $mail->envelope()->subject ?? $mail->definition()->name;
        } catch (Throwable) {
            return $mail->definition()->name;
        }
    }

    public static function isHalted(): bool
    {
        return (bool) Setting::declared('email.sending_halted');
    }

    /**
     * An address copied on everything, where the client wants one.
     *
     * @return array<int, string>
     */
    public static function monitoringBcc(): array
    {
        $bcc = Setting::declared('email.monitoring_bcc');

        return is_string($bcc) && trim($bcc) !== '' ? [trim($bcc)] : [];
    }
}
