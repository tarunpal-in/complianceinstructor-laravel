<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use App\Support\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * CLAUDE.md §8 improvement #3 — four admin roles over the named permissions,
 * with Filament navigation, resource pages and individual actions all gating on
 * permissions rather than on a role check.
 *
 * The permissions themselves live in PermissionCatalogue, which is also what
 * the roles screen renders. They were declared here as well until the staff
 * screen was built, which meant a new permission had to be added in two places
 * or it would exist without appearing on any role — the same shape as the
 * settings that lived in code with no screen.
 *
 * **These four roles are marked `is_system`.** Their permissions are owned
 * here, because syncPermissions() below would silently revert anything edited
 * in the admin on the next deploy. The client can create as many roles of their
 * own as they like, and this seeder never touches them.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalogue::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Flush again: findOrCreate() warms the registrar cache as it goes, so a
        // role sync straight afterwards would read a snapshot taken before these
        // permissions existed and throw PermissionDoesNotExist.
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::cases() as $case) {
            $role = Role::findOrCreate($case->value, 'web');

            $role->forceFill([
                // What makes it read-only in the admin. A role the client made
                // is theirs; these four are the ones this file owns.
                'is_system' => true,
                'description' => $case->description(),
            ])->save();

            $role->syncPermissions($this->permissionsFor($case));
        }
    }

    /** @return array<int, string> */
    private function permissionsFor(UserRole $role): array
    {
        return match ($role) {
            // Super Admin also gets a Gate::before bypass in AppServiceProvider,
            // so new permissions apply without reseeding. Syncing them anyway
            // keeps the admin UI showing the truth.
            UserRole::SuperAdmin => PermissionCatalogue::all(),

            // Catalogue and content, full CRUD. Deliberately no access to money.
            UserRole::ContentEditor => [
                ...PermissionCatalogue::crudFor([
                    'webinar', 'package', 'speaker', 'industry', 'accreditation',
                    'page', 'post', 'comment', 'navigation', 'redirect', 'seo_meta', 'media',
                ]),
                'moderate_comment',
                // Brand and Catalogue settings: logos, the promo bar and the
                // wording beside what is sold. Deliberately not Commerce or
                // Certificates — copy and money are different blast radii.
                ...PermissionCatalogue::readFor(['setting']), 'update_setting',
                // The mailing list is a marketing surface, so it belongs to
                // whoever writes the marketing. Deliberately without
                // `export_data` — running the list is not the same as being
                // able to walk away with it.
                ...PermissionCatalogue::crudFor(['subscriber']),
                'view_any_inquiry', 'view_inquiry',
            ],

            // Everything that touches money, plus reporting and exports.
            UserRole::Finance => [
                ...PermissionCatalogue::crudFor(['order', 'payment', 'coupon', 'subscription', 'offering']),
                ...PermissionCatalogue::readFor(['webinar', 'package', 'membership_plan', 'user', 'entitlement', 'media']),
                // Read-only on inquiries, because §8's funnel report runs
                // from them. Finance holds `export_data`, so it is also the
                // role that can pull the list.
                ...PermissionCatalogue::readFor(['inquiry', 'subscriber']),
                // Deliberately not `refund_payment`. Refunding is the one admin
                // action that moves money out irreversibly, and the client has
                // reserved it for the Super Admin. Finance still reads every
                // payment, records invoiced ones and issues manual payments —
                // it just cannot send money back.
                'manage_manual_payment', 'view_reports',
                'export_data', 'view_activity_log',
                // The email log, because "did the customer get their receipt?"
                // is a finance question. Deliberately without
                // `manage_email_sending`: reading what went out and deciding
                // what goes out at all are different jobs.
                'view_email_log', 'resend_email',
                // Order numbering appears on every invoice and the refund
                // window governs when money leaves. Read-only elsewhere.
                ...PermissionCatalogue::readFor(['setting']), 'manage_commerce_setting',
            ],

            // Read-mostly. Can resolve access problems, cannot move money.
            UserRole::Support => [
                ...PermissionCatalogue::readFor([
                    'order', 'payment', 'user', 'entitlement', 'certificate',
                    'subscription', 'webinar', 'package', 'media',
                ]),
                // Inquiries in full: Support is the desk that actually answers
                // them, and a register somebody can read but not update is one
                // where the reply gets recorded somewhere else.
                // Deliberately without `export_data` — answering an inquiry and
                // taking the whole list off the system are different things.
                ...PermissionCatalogue::crudFor(['inquiry']),
                // Read and update, not create or delete: "take me off your
                // list" arrives at the support desk, and they should be able
                // to act on it without being able to add addresses.
                ...PermissionCatalogue::readFor(['subscriber']), 'update_subscriber',
                /*
                 * The email log and the resend button, because this is the desk
                 * that hears "I never got my certificate" — and answering it
                 * without being able to look is how a support reply becomes a
                 * guess. Not `manage_email_sending`: they fix one send, they do
                 * not decide what the business emails anybody.
                 */
                'view_email_log', 'resend_email',
                /*
                 * `impersonate_user` used to be granted here. It was seeded and
                 * implemented nowhere, so it read as a capability Support had.
                 * There is also nothing to impersonate into yet — the learner
                 * dashboard is P5 and the application has no authenticated
                 * customer route at all. It returns when it is built, with the
                 * rules that make it safe, rather than sitting here meaning
                 * nothing.
                 */
                'grant_entitlement', 'revoke_entitlement',
            ],
        };
    }
}
