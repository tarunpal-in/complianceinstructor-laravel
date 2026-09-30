<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\User;
use App\Support\StaffLockout;
use Illuminate\Support\Facades\Password;
use RuntimeException;

/**
 * Turning admin access on and off, and changing what somebody can do.
 *
 * One class for the three because they share the guards, and because the guards
 * are the only interesting part: this is the screen where a mistake locks
 * everybody out of the application. StaffLockout answers whether each is
 * allowed and says why not in a sentence somebody can act on.
 */
class SetStaffAccess
{
    /**
     * Change which roles somebody holds.
     *
     * @param  array<int, string>  $roles
     */
    public function assign(User $target, array $roles, ?User $actor = null): User
    {
        $reason = StaffLockout::reasonCannotAssign($target, $roles, $actor);

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        $target->syncRoles($roles);

        return $target->refresh();
    }

    /**
     * Stop somebody reaching the admin, without erasing who they were.
     *
     * The roles stay. Seven columns across six tables point at a staff member
     * — who issued a certificate, who authorised a refund, who published a
     * comment — and deleting the account would turn every one of them into
     * "nobody". Deactivating keeps the history pointing at a real person with
     * a job title.
     */
    public function deactivate(User $target, ?string $reason, ?User $actor = null): User
    {
        $blocked = StaffLockout::reasonCannotDeactivate($target, $actor);

        if ($blocked !== null) {
            throw new RuntimeException($blocked);
        }

        $target->forceFill([
            'deactivated_at' => now(),
            'deactivated_by' => $actor?->getKey(),
            'deactivation_reason' => $reason,
        ])->save();

        return $target;
    }

    public function reinstate(User $target): User
    {
        $target->forceFill([
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
        ])->save();

        return $target;
    }

    /**
     * Send the reset link again.
     *
     * The same Fortify flow as the invitation — there is no second mechanism
     * and no way for an administrator to see or set the password.
     */
    public function resendInvitation(User $target): void
    {
        Password::broker()->sendResetLink(['email' => $target->email]);
    }

    /**
     * Clear somebody's two-factor setup after they lose their device.
     *
     * The only route back for a locked-out administrator, and the reason it is
     * on this screen rather than being a database job. It clears the secret and
     * the recovery codes, so the next login is password-only until they set it
     * up again.
     */
    public function clearTwoFactor(User $target): void
    {
        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
