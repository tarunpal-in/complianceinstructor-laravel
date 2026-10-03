<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\PasswordResetUrl;

/**
 * The reset link.
 *
 * §9's first critical finding in the predecessor was an unauthenticated
 * `POST /admin/update/password`. Laravel's password broker still owns the token —
 * hashed, single-use, expiring, throttled. This changes only how the mail is
 * carried, so that "I never got the link" has a row somebody can look at.
 *
 * Reaches here two ways: `User::sendPasswordResetNotification` for anything that
 * calls the broker directly, and an interception in AppServiceProvider for
 * Filament's reset page, which passes the broker its own callback and would
 * otherwise bypass the log entirely.
 */
final class ResetPassword extends LoggedMail
{
    public function __construct(
        public readonly User $user,
        public readonly string $resetUrl,
    ) {}

    public static function emailKey(): string
    {
        return 'account.password_reset';
    }

    /** From a token, for the paths that hand us one rather than a URL. */
    public static function forToken(User $user, string $token): self
    {
        return new self($user, PasswordResetUrl::for($user, $token));
    }

    /**
     * Never rebuilt.
     *
     * The token is single-use and is not stored here — deliberately, because
     * storing a live reset token in a log readable by Finance and Support would
     * be an account takeover waiting for a curious afternoon. The catalogue says
     * the same thing to the admin in words.
     */
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
        // No token. See rebuild().
        return ['user_id' => $this->user->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'Reset your password';
    }

    protected function markdownView(): string
    {
        return 'emails.account.reset-password';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'user' => $this->user,
            'resetUrl' => $this->resetUrl,
            'expiresInMinutes' => (int) config('auth.passwords.users.expire', 60),
        ];
    }
}
