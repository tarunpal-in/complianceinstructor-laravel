<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Models\ImportMap;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Customers, from `wp_users` — Phase 2.
 *
 * Only real people: a user with the WooCommerce `customer` role or billing
 * details on file. That is ~3,900 accounts; the ~16,900 `subscriber`-only
 * records are bot sign-ups from blog comments (disposable-domain emails, random
 * display names) and are left behind. Staff are not imported — the client sets
 * up admins fresh.
 *
 * The WordPress password hash is carried over verbatim (the hashed cast leaves
 * it alone, since LegacyAwareHasher reports it as already hashed), so the person
 * signs in with their existing password and it upgrades to bcrypt on first
 * login. Established accounts are marked verified so no one is asked to
 * re-confirm. Customers hold no role — "customer" is the absence of the staff
 * roles (§5.8).
 */
class UserImporter extends Importer
{
    public function label(): string
    {
        return 'Users';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $rows = Legacy::table('users as u')
            ->join('usermeta as caps', fn ($j) => $j->on('caps.user_id', '=', 'u.ID')->where('caps.meta_key', 'wp_capabilities'))
            ->leftJoin('usermeta as bf', fn ($j) => $j->on('bf.user_id', '=', 'u.ID')->where('bf.meta_key', 'billing_first_name'))
            ->leftJoin('usermeta as bl', fn ($j) => $j->on('bl.user_id', '=', 'u.ID')->where('bl.meta_key', 'billing_last_name'))
            ->leftJoin('usermeta as fn', fn ($j) => $j->on('fn.user_id', '=', 'u.ID')->where('fn.meta_key', 'first_name'))
            ->leftJoin('usermeta as ln', fn ($j) => $j->on('ln.user_id', '=', 'u.ID')->where('ln.meta_key', 'last_name'))
            ->where(function ($w): void {
                $w->where('caps.meta_value', 'like', '%customer%')
                    ->orWhere(fn ($b) => $b->whereNotNull('bf.meta_value')->where('bf.meta_value', '<>', ''));
            })
            ->select(
                'u.ID', 'u.user_email', 'u.user_pass', 'u.display_name', 'u.user_registered',
                'bf.meta_value as bfirst', 'bl.meta_value as blast',
                'fn.meta_value as ffirst', 'ln.meta_value as flast',
            )
            ->get();

        foreach ($rows as $row) {
            $legacyId = (int) $row->ID;
            $email = strtolower(trim((string) $row->user_email));

            if ($email === '' || ! str_contains($email, '@')) {
                $result->failed("User {$legacyId}: missing or invalid email.");

                continue;
            }

            // Two legacy accounts can share an email (or it may already exist
            // from the seed). Map this legacy id onto the account that owns the
            // email and move on, rather than colliding on the unique index.
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && ImportMap::localId('user', $legacyId) !== $existing->getKey()) {
                ImportMap::remember('user', $legacyId, $existing);
                $result->skipped();

                continue;
            }

            $this->sync($result, 'user', $legacyId, User::class, function (User $user) use ($row, $email): void {
                $user->email = $email;
                $user->name = $this->resolveName($row, $email);
                // The raw WordPress hash; the hashed cast will not touch it.
                $user->password = (string) $row->user_pass;
                // An established account is already trusted — don't make a
                // migrated customer re-verify to log in.
                $user->email_verified_at = $row->user_registered ?: now();
            });
        }

        return $result;
    }

    private function resolveName(object $row, string $email): string
    {
        $first = trim((string) ($row->bfirst ?? $row->ffirst ?? ''));
        $last = trim((string) ($row->blast ?? $row->flast ?? ''));
        $name = trim($first.' '.$last);

        if ($name !== '') {
            return $name;
        }

        $display = trim((string) $row->display_name);

        return $display !== '' ? $display : Str::before($email, '@');
    }
}
