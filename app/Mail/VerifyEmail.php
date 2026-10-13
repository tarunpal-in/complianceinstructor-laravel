<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;

/**
 * The address-verification link for a newly registered customer.
 *
 * The customer panel (docs/auth.md's chosen owner of public auth) asks Filament
 * to verify new addresses. Filament would send its own notification and bypass
 * the log; AppServiceProvider intercepts it and sends this instead, so a
 * verification email is a row somebody can look at like every other — "I never
 * got the link" is asked about sign-up almost as often as about reset.
 *
 * The link is a signed, expiring URL Filament computed, so this is never
 * replayed from the log: a stored body holds a link that is already dead.
 */
final class VerifyEmail extends LoggedMail
{
    public function __construct(
        public readonly User $user,
        public readonly string $verifyUrl,
    ) {}

    public static function emailKey(): string
    {
        return 'account.verify_email';
    }

    /** Never rebuilt — the signed link expires; a fresh one is issued instead. */
    public static function rebuild(array $payload): ?static
    {
        return null;
    }

    public function recipientEmail(): string
    {
        return (string) $this->user->email;
    }

    public function recipientName(): string
    {
        return $this->user->name;
    }

    public function recipientUser(): User
    {
        return $this->user;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return ['user_id' => $this->user->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'Verify your email address';
    }

    protected function markdownView(): string
    {
        return 'emails.account.verify-email';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'user' => $this->user,
            'verifyUrl' => $this->verifyUrl,
        ];
    }
}
