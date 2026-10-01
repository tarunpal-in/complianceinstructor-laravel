<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

/**
 * Where a reset token sends somebody.
 *
 * There are two reset screens and they are not interchangeable. Staff reset
 * inside the panel; a customer resets on the public site. Sending a customer to
 * `/admin/password-reset` would land them on a login they cannot use, and
 * sending staff to a public route that does not exist yet would be a 404 on the
 * one email that exists to recover a locked-out account.
 *
 * The public route is absent until the learner area lands in P5, so everybody
 * currently goes to the panel. `Route::has` rather than a dated comment, so this
 * starts being right on its own the moment that route exists.
 */
final class PasswordResetUrl
{
    public static function for(User $user, string $token): string
    {
        if (! $user->isStaff() && Route::has('password.reset')) {
            return route('password.reset', ['token' => $token, 'email' => $user->email]);
        }

        return Filament::getResetPasswordUrl($token, $user);
    }
}
