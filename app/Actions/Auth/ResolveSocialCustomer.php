<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use RuntimeException;

/**
 * Turns a verified social identity into the customer behind it — §5.8 / §9.
 *
 * Find by email, or create a customer (no role) on first sign-in. A social
 * provider has already proved the address, so the account is marked verified.
 *
 * Staff are refused, exactly as they are on the customer panel: letting a
 * role-holder in through a social button would hand them a `web` session that
 * never faced the admin two-factor challenge — the bypass docs/auth.md exists to
 * prevent. A provider that shares no email cannot be matched to an account, so
 * that is refused too.
 */
class ResolveSocialCustomer
{
    public function __invoke(SocialiteUser $social): User
    {
        $email = trim((string) $social->getEmail());

        if ($email === '') {
            throw new RuntimeException('did-not-share-email');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            if ($existing->isStaff()) {
                throw new RuntimeException('staff');
            }

            if ($existing->email_verified_at === null) {
                $existing->forceFill(['email_verified_at' => now()])->save();
            }

            return $existing;
        }

        $user = new User([
            'name' => $this->name($social, $email),
            'email' => $email,
            // A throwaway: a social-only account signs in through the provider.
            // They can set a password later via "forgot password" if they want
            // an email-and-password route in as well.
            'password' => Str::random(40),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function name(SocialiteUser $social, string $email): string
    {
        $name = trim((string) ($social->getName() ?? ''));

        if ($name !== '') {
            return $name;
        }

        $nickname = trim((string) ($social->getNickname() ?? ''));

        return $nickname !== '' ? $nickname : Str::before($email, '@');
    }
}
