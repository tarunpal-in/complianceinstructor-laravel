<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PasswordResetUrl;

/**
 * Somebody has been given admin access.
 *
 * Replaces what the invitation used to be: a bare password-reset link,
 * arriving at somebody who never asked to reset anything, with no mention of who
 * sent it or what it was for. That reads as a phishing attempt, and the sensible
 * reaction to it is to delete it.
 *
 * **There is still no password here and there must never be one.** §9's first
 * critical finding was an endpoint that let somebody set an administrator's
 * password. The broker's token does the work; this only explains it.
 */
final class StaffInvitation extends LoggedMail
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public readonly User $user,
        public readonly string $resetUrl,
        public readonly array $roles,
        public readonly ?User $invitedBy = null,
    ) {}

    public static function emailKey(): string
    {
        return 'internal.staff_invitation';
    }

    public static function forToken(User $user, string $token, ?User $invitedBy = null): self
    {
        return new self(
            user: $user,
            resetUrl: PasswordResetUrl::for($user, $token),
            // Named roles rather than `content_editor`. Somebody being told what
            // they can now do should be able to read it.
            roles: $user->roles()->orderBy('name')->pluck('name')->map(
                fn (mixed $name): string => UserRole::tryFrom((string) $name)?->label() ?? (string) $name,
            )->all(),
            invitedBy: $invitedBy,
        );
    }

    /** Never rebuilt — the token is single-use. "Send invitation again" issues a fresh one. */
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

    public function relatedRecord(): User
    {
        return $this->user;
    }

    /** @return array<string, mixed> */
    public function logPayload(): array
    {
        return [
            'user_id' => $this->user->getKey(),
            'roles' => $this->roles,
            'invited_by_id' => $this->invitedBy?->getKey(),
        ];
    }

    protected function subjectLine(): string
    {
        return 'You have been given access to '.self::sender()->name;
    }

    protected function markdownView(): string
    {
        return 'emails.staff.invitation';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'user' => $this->user,
            'resetUrl' => $this->resetUrl,
            'roles' => $this->roles,
            // Named, because "an administrator invited you" is the sentence that
            // turns this from a suspicious link into an expected one.
            'invitedByName' => $this->invitedBy?->name,
            'expiresInMinutes' => (int) config('auth.passwords.users.expire', 60),
        ];
    }
}
