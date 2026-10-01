<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Email\Dispatcher;
use App\Mail\StaffInvitation;
use App\Models\User;
use App\Support\RoleAssignment;
use App\Support\StaffLockout;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Give somebody admin access — §4 rule 2.
 *
 * **There is no password here, and there must never be one.** §9's first
 * critical finding in the predecessor was an unauthenticated
 * `POST /admin/update/password`: supply an admin's email and a new password and
 * take the account. Fortify owns password setting, with signed single-use
 * expiring tokens and throttling, and a "set their password" field on an admin
 * screen walks straight back into the same class of problem — an administrator
 * who can set another administrator's password can become them, and nothing in
 * the log says so.
 *
 * So the account is created with an unusable password and a reset link is sent.
 * The person sets their own, and nobody else ever knows it.
 */
class InviteStaffMember
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __invoke(string $name, string $email, array $roles, ?User $actor = null): User
    {
        $name = trim($name);
        $email = Str::lower(trim($email));

        if ($name === '' || $email === '') {
            throw new RuntimeException('A name and an email address are needed.');
        }

        if ($roles === []) {
            // An account with no role cannot reach the panel, so inviting
            // somebody to nothing is a mistake rather than a choice.
            throw new RuntimeException('Give them at least one role, or they cannot get in.');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->grantAccessTo($existing, $roles, $actor);
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            /*
             * Random and thrown away. Not null: a null password would let
             * anything that checks `password === null` treat the account as
             * passwordless, and a long random string that nobody has is the
             * safe shape of "not set yet".
             */
            'password' => Str::random(64),
        ]);

        RoleAssignment::sync($user, $roles, $actor);
        $this->sendInvitation($user, $actor);

        return $user;
    }

    /**
     * An existing account — usually a customer being taken on as staff.
     *
     * Their password is left alone. They already have one, and resetting it
     * because somebody gave them a role would lock them out of the account
     * they already use.
     *
     * @param  array<int, string>  $roles
     */
    private function grantAccessTo(User $user, array $roles, ?User $actor): User
    {
        $reason = StaffLockout::reasonCannotAssign($user, $roles, $actor);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        RoleAssignment::sync($user, $roles, $actor);

        if ($user->isDeactivated()) {
            $user->forceFill([
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ])->save();
        }

        return $user;
    }

    /**
     * The invitation, with Fortify's token inside it.
     *
     * `createToken` rather than `sendResetLink`, which is the whole change here.
     * `sendResetLink` mails Fortify's **password-reset** template — so somebody
     * being given admin access received a bare "reset your password" link, with
     * no mention of who sent it or what it was for. That reads as phishing, and
     * deleting it is the sensible reaction.
     *
     * The token is still Fortify's: signed, single-use, expiring, throttled. §9
     * keeps it the only owner of password setting. Only the envelope changed.
     *
     * Swallowed rather than thrown: a mail failure must not roll back an account
     * that was created successfully, and the admin can resend.
     */
    public function sendInvitation(User $user, ?User $invitedBy = null): void
    {
        try {
            $token = Password::broker()->createToken($user);

            app(Dispatcher::class)->send(
                StaffInvitation::forToken($user->refresh(), $token, $invitedBy),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
