<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Changing what somebody may do, with a record of who changed it.
 *
 * §8 requires "every mutation written to `activity_log` with the actor", and
 * until this existed a role change was the one mutation that was not. The
 * reason is easy to miss: roles live in a pivot table, and attaching a pivot
 * row fires no Eloquent event, so `LogsActivity` on User never sees it. An
 * account could be made Super Admin and the trail would show nothing at all —
 * which is precisely the question the log's Access tab exists to answer.
 *
 * Two routes were available and only one of them is honest.
 *
 * spatie fires `RoleAttachedEvent` and `RoleDetachedEvent` when
 * `permission.events_enabled` is true, and listening to those sounds more
 * structural than a wrapper. It is not. `syncRoles()` detaches everything and
 * reattaches, so a save that changed nothing fires both events and would write
 * a change nobody made; and Filament's own `relationship()` handling calls
 * `sync()` straight on the pivot, which fires neither — so the one path most
 * likely to be used by future code is the one the listener cannot see.
 *
 * So: one method that does the change and records it together, and
 * StaffAndRolesTest fails the build if anything under app/ calls `syncRoles`,
 * `assignRole` or `removeRole` itself. The guard is the test, not the hope.
 */
final class RoleAssignment
{
    /**
     * Give somebody exactly this set of roles, and record who did it.
     *
     * Silent when nothing moved. Filament saves the whole form on every edit,
     * so a staff member whose email was corrected would otherwise collect a
     * "roles changed" entry saying the roles went from Finance to Finance —
     * the same noise `logOnlyDirty` exists to keep out of every other row.
     *
     * @param  array<int, string>  $roles  role names, not ids
     * @param  User|null  $actor  who is making the change; the signed-in user when null
     */
    public static function sync(User $target, array $roles, ?User $actor = null): void
    {
        $before = self::held($target);

        $target->syncRoles($roles);

        $after = self::held($target);

        if ($before === $after) {
            return;
        }

        /*
         * Written as an ordinary before/after so it reads through the same
         * narrator as everything else: "Staff account — changed roles", with
         * Support → Finance underneath. A bespoke event name would need
         * bespoke handling in four places to say the same thing.
         *
         * causedBy(null) is not anonymous — spatie leaves the causer to its
         * resolver, which is the signed-in user. A seeder or a console command
         * has nobody signed in and the row reads "The system", which is true.
         */
        activity('staff')
            ->performedOn($target)
            ->causedBy($actor)
            ->event('updated')
            ->withProperties([
                'attributes' => ['roles' => self::listed($after)],
                'old' => ['roles' => self::listed($before)],
            ])
            ->log('updated');
    }

    /**
     * The roles on the record right now.
     *
     * Through the relation query rather than the loaded relation: after a sync
     * the loaded copy is stale, and comparing a stale before with a stale after
     * would report every change as no change.
     *
     * @return array<int, string>
     */
    private static function held(User $target): array
    {
        return $target->roles()
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    /**
     * Role names as one string, or null for none.
     *
     * Null rather than an empty array so the narrator prints "nothing" — "from
     * nothing to Super Admin" is how an account being given access should read.
     *
     * @param  array<int, string>  $roles
     */
    private static function listed(array $roles): ?string
    {
        return $roles === [] ? null : implode(', ', $roles);
    }
}
