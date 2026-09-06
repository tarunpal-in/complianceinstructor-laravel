<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CLAUDE.md §8 improvement #3 — four admin roles over ~40 named permissions,
 * with Filament navigation, resource pages and individual actions all gating on
 * permissions rather than on a role check.
 *
 * Permissions follow `{action}_{subject}`. Resource subjects gain the standard
 * five actions; privileged operations are named individually so they can be
 * withheld from a role that otherwise has full access to the resource — Support
 * can read an order without being able to refund it.
 */
class RolePermissionSeeder extends Seeder
{
    /** Subjects getting the standard five CRUD actions, grouped as in §6. */
    private const CRUD_SUBJECTS = [
        // Catalogue
        'webinar', 'package', 'speaker', 'industry', 'accreditation', 'offering',
        // Commerce
        'order', 'payment', 'coupon',
        // Memberships
        'membership_plan', 'subscription',
        // Learners
        'user', 'entitlement', 'certificate',
        // Content
        'page', 'post', 'navigation', 'redirect', 'seo_meta', 'media',
        // Engagement
        'lead', 'subscriber', 'inquiry',
        // System
        'role', 'setting',
    ];

    /** Operations that must be grantable independently of CRUD. */
    private const PRIVILEGED = [
        'refund_payment',
        'grant_entitlement',
        'revoke_entitlement',
        'issue_certificate',
        'revoke_certificate',
        'impersonate_user',
        'view_activity_log',
        'view_reports',
        'export_data',
        'manage_manual_payment',
    ];

    public function run(): void
    {
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Flush again: findOrCreate() warms the registrar cache as it goes, so a
        // role sync straight afterwards would read a snapshot taken before these
        // permissions existed and throw PermissionDoesNotExist.
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::cases() as $case) {
            $role = Role::findOrCreate($case->value, 'web');
            $role->syncPermissions($this->permissionsFor($case));
        }
    }

    /** @return array<int, string> */
    private function allPermissions(): array
    {
        $permissions = [];

        foreach (self::CRUD_SUBJECTS as $subject) {
            foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "{$action}_{$subject}";
            }
        }

        return [...$permissions, ...self::PRIVILEGED];
    }

    /** @return array<int, string> */
    private function permissionsFor(UserRole $role): array
    {
        return match ($role) {
            // Super Admin also gets a Gate::before bypass in AppServiceProvider,
            // so new permissions apply without reseeding. Syncing them anyway
            // keeps the admin UI showing the truth.
            UserRole::SuperAdmin => $this->allPermissions(),

            // Catalogue and content, full CRUD. Deliberately no access to money.
            UserRole::ContentEditor => [
                ...$this->crudFor([
                    'webinar', 'package', 'speaker', 'industry', 'accreditation',
                    'page', 'post', 'navigation', 'redirect', 'seo_meta', 'media',
                ]),
                'view_any_lead', 'view_lead',
            ],

            // Everything that touches money, plus reporting and exports.
            UserRole::Finance => [
                ...$this->crudFor(['order', 'payment', 'coupon', 'subscription', 'offering']),
                ...$this->readFor(['webinar', 'package', 'membership_plan', 'user', 'entitlement', 'media']),
                'refund_payment', 'manage_manual_payment', 'view_reports',
                'export_data', 'view_activity_log',
            ],

            // Read-mostly. Can resolve access problems, cannot move money.
            UserRole::Support => [
                ...$this->readFor([
                    'order', 'payment', 'user', 'entitlement', 'certificate',
                    'subscription', 'webinar', 'package', 'inquiry', 'media',
                ]),
                'grant_entitlement', 'revoke_entitlement', 'impersonate_user',
                'update_inquiry',
            ],
        };
    }

    /**
     * @param  array<int, string>  $subjects
     * @return array<int, string>
     */
    private function crudFor(array $subjects): array
    {
        $permissions = [];

        foreach ($subjects as $subject) {
            foreach (['view_any', 'view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "{$action}_{$subject}";
            }
        }

        return $permissions;
    }

    /**
     * @param  array<int, string>  $subjects
     * @return array<int, string>
     */
    private function readFor(array $subjects): array
    {
        $permissions = [];

        foreach ($subjects as $subject) {
            $permissions[] = "view_any_{$subject}";
            $permissions[] = "view_{$subject}";
        }

        return $permissions;
    }
}
