<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\LoggedMail;
use App\Models\EmailSend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Hands one email to the mail server and records what happened.
 *
 * Queued per §4 rule 7 — an SMTP round trip must never sit inside the request
 * that placed an order or reset a password. Gmail's SMTP is also the slowest
 * thing in the stack and the most likely to be temporarily unavailable, which is
 * precisely the shape of work a queue is for.
 *
 * The rate limiter is not a nicety. A consumer Gmail account throttles at
 * roughly 20–30 messages a minute and answers a burst with a temporary block on
 * the whole account, taking password resets down with the reminder blast that
 * caused it.
 */
class SendEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then the row says failed.
     *
     * Deliberately low. A retry loop against a mail server that is rejecting us
     * burns the sending reputation this account cannot afford to lose, and a
     * genuinely transient failure clears within three tries.
     */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $sendId,
        public readonly LoggedMail $mail,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('email')];
    }

    public function handle(): void
    {
        $send = EmailSend::query()->find($this->sendId);

        if ($send === null) {
            // The log row was pruned or the database was reset under a queued
            // job. Sending anyway would produce an email with no record of
            // itself, which is the one outcome this whole system exists to stop.
            return;
        }

        $send->markSending();

        $sent = Mail::to($send->to_email, $send->to_name)
            ->bcc($send->bcc ?? [])
            ->send($this->mail);

        $send->markSent(
            $sent?->getMessageId(),
            // Which mail config did it, so a delivery problem can be pinned to
            // the period it started in rather than to "SMTP".
            (string) config('mail.default'),
        );
    }

    /**
     * Record the failure on the row the admin will be looking at.
     *
     * Laravel logs it too, but the log is not where somebody goes when a
     * customer says their certificate never arrived.
     */
    public function failed(?Throwable $exception): void
    {
        EmailSend::query()->find($this->sendId)?->markFailed(
            $exception?->getMessage() ?? 'The send failed with no message.',
        );
    }
}
