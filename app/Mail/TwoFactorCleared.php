<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;

/**
 * An administrator cleared somebody's two-factor setup.
 *
 * The same shape of safeguard as PasswordChanged, and the reason it is Required
 * is written into the action it fires from: whoever does this leaves the account
 * on a password alone until its owner sets it up again, and the owner may not be
 * the one who asked. It is also now recorded in `activity_log`; this is the half
 * of that pair the person affected can actually see.
 */
final class TwoFactorCleared extends LoggedMail
{
    public function __construct(
        public readonly User $user,
        public readonly ?User $clearedBy = null,
    ) {}

    public static function emailKey(): string
    {
        return 'account.two_factor_cleared';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $user = User::query()->find($payload['user_id'] ?? null);

        return $user === null
            ? null
            : new self($user, User::query()->find($payload['cleared_by_id'] ?? null));
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
            'cleared_by_id' => $this->clearedBy?->getKey(),
        ];
    }

    protected function subjectLine(): string
    {
        return 'Your two-factor authentication was reset';
    }

    protected function markdownView(): string
    {
        return 'emails.account.two-factor-cleared';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'user' => $this->user,
            // Named where we know who. "An administrator" is better than
            // nothing, but a name is what makes this checkable.
            'clearedByName' => $this->clearedBy?->name,
        ];
    }
}
