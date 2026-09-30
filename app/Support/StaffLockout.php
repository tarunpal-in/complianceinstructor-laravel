<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

/**
 * The guards that stop somebody locking the business out of its own admin.
 *
 * This is the one screen where a bug means nobody can get in — not a wrong
 * figure or a missing button, but an application with no way back into it
 * short of a developer with database access. Every refusal here is a sentence
 * rather than a boolean, so the admin can say *why* rather than greying out a
 * button for no visible reason.
 *
 * All four rules exist because each is reachable by somebody being helpful. The
 * last Super Admin tidying up their own account; an administrator deactivating
 * themselves at the end of their last day; somebody trimming a role list and
 * taking their own Super Admin off by accident.
 */
final class StaffLockout
{
    /**
     * Why this account cannot be switched off, or null if it can.
     */
    public static function reasonCannotDeactivate(User $target, ?User $actor = null): ?string
    {
        if ($target->isDeactivated()) {
            return 'This account is already deactivated.';
        }

        if (! $target->isStaff()) {
            return 'This account has no admin access to remove.';
        }

        /*
         * Somebody deactivating themselves is not protecting anything — they
         * are one click from being locked out of the screen that would undo
         * it. Another administrator does it on their last day.
         */
        if ($actor !== null && $actor->is($target)) {
            return 'You cannot deactivate your own account. Ask another administrator.';
        }

        if (self::isLastActiveSuperAdmin($target)) {
            return 'This is the only active Super Admin. Give somebody else the role first, or nobody can get back in.';
        }

        return null;
    }

    public static function canDeactivate(User $target, ?User $actor = null): bool
    {
        return self::reasonCannotDeactivate($target, $actor) === null;
    }

    /**
     * Why this account's roles cannot be changed to the given set.
     *
     * Checked against what the roles would become rather than against what is
     * being removed, because the dangerous case is the *result*: an account
     * left without Super Admin when it was the last one holding it.
     *
     * @param  array<int, string>  $roles
     */
    public static function reasonCannotAssign(User $target, array $roles, ?User $actor = null): ?string
    {
        $keepsSuperAdmin = in_array(UserRole::SuperAdmin->value, $roles, true);

        if ($keepsSuperAdmin) {
            return null;
        }

        /*
         * Removing your own Super Admin is the quietest way to lose access:
         * the save succeeds, the screen reloads, and half the panel is gone.
         * Another administrator can still demote you.
         */
        if ($actor !== null && $actor->is($target) && $target->hasRole(UserRole::SuperAdmin->value)) {
            return 'You cannot remove your own Super Admin role. Ask another administrator.';
        }

        if (self::isLastActiveSuperAdmin($target)) {
            return 'This is the only active Super Admin. Give somebody else the role before taking it away.';
        }

        return null;
    }

    /** @param  array<int, string>  $roles */
    public static function canAssign(User $target, array $roles, ?User $actor = null): bool
    {
        return self::reasonCannotAssign($target, $roles, $actor) === null;
    }

    /**
     * Whether this account is the only Super Admin still able to log in.
     *
     * Deactivated ones do not count. An account that cannot reach the panel is
     * no use as the way back into it, and counting it would let somebody
     * deactivate the last one who could.
     */
    public static function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $target->hasRole(UserRole::SuperAdmin->value)) {
            return false;
        }

        $others = User::query()
            ->whereKeyNot($target->getKey())
            ->whereNull('deactivated_at')
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::SuperAdmin->value))
            ->count();

        return $others === 0;
    }

    /** How many administrators could still get in — for a warning on the list. */
    public static function activeSuperAdminCount(): int
    {
        return User::query()
            ->whereNull('deactivated_at')
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::SuperAdmin->value))
            ->count();
    }
}
