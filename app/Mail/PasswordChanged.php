<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;

/**
 * Somebody's password changed.
 *
 * Not a courtesy. If the person who changed it was not the owner, this is the
 * only thing that tells the owner — which is the same reasoning as the refund
 * notice going to every Super Admin including whoever raised it. It carries no
 * link, deliberately: an unexpected security email whose advice is "click here"
 * trains people into exactly the habit that loses accounts.
 */
final class PasswordChanged extends LoggedMail
{
    public function __construct(public readonly User $user) {}

    public static function emailKey(): string
    {
        return 'account.password_changed';
    }

    /** @param  array<string, mixed>  $payload */
    public static function rebuild(array $payload): ?static
    {
        $user = User::query()->find($payload['user_id'] ?? null);

        return $user === null ? null : new self($user);
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
        return ['user_id' => $this->user->getKey()];
    }

    protected function subjectLine(): string
    {
        return 'Your password was changed';
    }

    protected function markdownView(): string
    {
        return 'emails.account.password-changed';
    }

    /** @return array<string, mixed> */
    protected function viewData(): array
    {
        return [
            'user' => $this->user,
            'changedAt' => now(),
        ];
    }
}
