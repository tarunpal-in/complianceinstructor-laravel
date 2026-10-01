<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\User;
use App\Support\RoleAssignment;
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

        // Not syncRoles: a pivot write fires no model event, so the change
        // would leave no trace of itself or of who made it. See RoleAssignment.
        RoleAssignment::sync($target, $roles, $actor);

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
    public function clearTwoFactor(User $target, ?User $actor = null): void
    {
        // Raw, so reading it cannot trip the decrypt that the accessor does.
        $hadSetup = ($target->getAttributes()['two_factor_secret'] ?? null) !== null;

        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if (! $hadSetup) {
            return;
        }

        /*
         * Recorded by hand for the same reason roles are: none of the three
         * columns is in logOnly — a secret and a set of recovery codes must
         * never be written to the log — so the save above writes nothing, and
         * taking an administrator's second factor off would be invisible.
         *
         * It is not a small act. Whoever does this leaves the account on a
         * password alone until its owner sets it up again, and the owner may
         * not be the one who asked.
         */
        activity('staff')
            ->performedOn($target)
            ->causedBy($actor)
            ->event('updated')
            ->withProperties([
                'attributes' => ['two_factor' => 'cleared'],
                'old' => ['two_factor' => 'set up'],
            ])
            ->log('updated');
    }
}
