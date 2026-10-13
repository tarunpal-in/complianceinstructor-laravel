<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Models\EmailSend;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;

/*
 * The customer panel (docs/auth.md) verifies new addresses through Filament's own
 * VerifyEmail notification. Left alone that would be the one account email with
 * no row in the log; AppServiceProvider intercepts it and carries it through the
 * Dispatcher instead. This proves the interception, not Filament's UI.
 */

it('logs the email-verification mail through the Dispatcher', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'newbie@example.test']);

    $notification = new FilamentVerifyEmail;
    $notification->url = 'https://compliance.test/account/email-verification/verify/1/hash';

    $user->notify($notification);

    $send = EmailSend::query()->where('email_key', 'account.verify_email')->sole();

    expect($send->to_email)->toBe('newbie@example.test')
        ->and($send->status)->toBe(EmailStatus::Sent)
        // The signed link Filament computed is carried verbatim into the mail.
        ->and($send->body_html)->toContain('account/email-verification/verify/1/hash');
});

it('records the verification mail against the account it concerns', function (): void {
    $user = User::factory()->unverified()->create();

    $notification = new FilamentVerifyEmail;
    $notification->url = 'https://compliance.test/account/email-verification/verify/2/hash';

    $user->notify($notification);

    expect(EmailSend::query()->where('email_key', 'account.verify_email')->sole()->user_id)
        ->toBe($user->getKey());
});
