<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every permission this application has, grouped so a person can read them.
 *
 * §8 calls for "~40 named permissions"; there are 140. That is fine as a list
 * a Gate checks and unusable as a form — a role screen showing 140 unsorted
 * checkboxes is one somebody ticks at random. This groups them the way the
 * admin panel is grouped, and gives the privileged ones a sentence saying what
 * they actually let somebody do.
 *
 * It is also the single list. RolePermissionSeeder used to carry its own copy
 * of the subjects and the privileged operations, which meant a new permission
 * had to be added in two places or the role screen would not show it — the same
 * shape as the settings that existed in code with no screen.
 */
final class PermissionCatalogue
{
    /** The five actions every resource subject gets. */
    public const ACTIONS = ['view_any', 'view', 'create', 'update', 'delete'];

    /**
     * Resource subjects, by the panel group they belong to.
     *
     * The key is the permission subject, the value is what to call it.
     *
     * @var array<string, array<string, string>>
     */
    public const SUBJECTS = [
        'Catalogue' => [
            'webinar' => 'Webinars',
            'package' => 'Packages',
            'speaker' => 'Speakers',
            'industry' => 'Industries',
            'accreditation' => 'Accrediting bodies',
            'offering' => 'Offerings and prices',
        ],
        'Commerce' => [
            'order' => 'Orders',
            'payment' => 'Payments',
            'coupon' => 'Coupons',
        ],
        'Memberships' => [
            'membership_plan' => 'Plans',
            'subscription' => 'Memberships',
        ],
        'Learners' => [
            'user' => 'Customers',
            'entitlement' => 'Access grants',
            'certificate' => 'Certificates',
        ],
        'Content' => [
            'page' => 'Pages',
            'post' => 'Blog articles',
            'comment' => 'Comments',
            'navigation' => 'Navigation',
            'redirect' => 'Redirects',
            'seo_meta' => 'SEO',
            'media' => 'Media library',
        ],
        'Inbox' => [
            'subscriber' => 'Mailing list',
            'inquiry' => 'Inquiries',
        ],
        'System' => [
            'role' => 'Staff and roles',
            'setting' => 'Settings',
        ],
    ];

    /**
     * Operations named individually, so they can be withheld from a role that
     * otherwise has full access to the resource.
     *
     * Each carries a warning where the consequence is not obvious from the
     * name. Somebody ticking boxes on a role screen deserves to know which of
     * them moves money.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const PRIVILEGED = [
        'Commerce' => [
            'refund_payment' => ['Refund a payment', 'Sends money back. The one admin action that cannot be undone.'],
            'manage_manual_payment' => ['Record an invoiced payment', 'Marks an order paid without a gateway confirming it.'],
            'manage_commerce_setting' => ['Change order numbering and refund settings', 'Order numbers appear on every invoice.'],
        ],
        'Learners' => [
            'grant_entitlement' => ['Give somebody access', 'Hands out content without a purchase.'],
            'revoke_entitlement' => ['Take access back', 'Also returns a membership allowance where one was spent.'],
            'issue_certificate' => ['Issue a certificate', 'Awards accredited credit hours in the business\'s name.'],
            'revoke_certificate' => ['Withdraw a certificate', 'The accrediting body may need telling separately.'],
            'manage_certificate_setting' => ['Change certificate rules', 'Decides when credits are awarded automatically.'],
        ],
        'Content' => [
            'moderate_comment' => ['Publish and bin comments', 'Publishes somebody else\'s words under the masthead.'],
        ],
        'System' => [
            'view_reports' => ['See reports', 'Includes revenue and everything the business took.'],
            'export_data' => ['Export data', 'Takes whole lists off the system as a file.'],
            'view_activity_log' => ['See the activity log', 'Who changed what, across the whole admin.'],
            'view_setup_checklist' => ['See launch readiness', 'The getting-started checklist.'],
            'manage_business_setting' => ['Change business details and email settings', 'Company identity and what goes out.'],
        ],
    ];

    /**
     * Every permission name, flat.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::SUBJECTS as $subjects) {
            foreach (array_keys($subjects) as $subject) {
                foreach (self::ACTIONS as $action) {
                    $permissions[] = "{$action}_{$subject}";
                }
            }
        }

        foreach (self::PRIVILEGED as $operations) {
            foreach (array_keys($operations) as $permission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * The whole catalogue as a form would render it.
     *
     * Group → permission → label, with the resource actions expanded into
     * readable phrases rather than "update_membership_plan".
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::SUBJECTS as $group => $subjects) {
            foreach ($subjects as $subject => $noun) {
                foreach (self::ACTIONS as $action) {
                    $groups[$group]["{$action}_{$subject}"] = self::describeAction($action, $noun);
                }
            }
        }

        foreach (self::PRIVILEGED as $group => $operations) {
            foreach ($operations as $permission => [$label]) {
                $groups[$group][$permission] = $label;
            }
        }

        return $groups;
    }

    /** The warning for a privileged permission, if it has one. */
    public static function warning(string $permission): ?string
    {
        foreach (self::PRIVILEGED as $operations) {
            if (isset($operations[$permission])) {
                return $operations[$permission][1];
            }
        }

        return null;
    }

    public static function label(string $permission): string
    {
        foreach (self::grouped() as $permissions) {
            if (isset($permissions[$permission])) {
                return $permissions[$permission];
            }
        }

        return $permission;
    }

    /**
     * The five actions for each of these subjects.
     *
     * @param  array<int, string>  $subjects
     * @return array<int, string>
     */
    public static function crudFor(array $subjects): array
    {
        $permissions = [];

        foreach ($subjects as $subject) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = "{$action}_{$subject}";
            }
        }

        return $permissions;
    }

    /**
     * Look but do not touch.
     *
     * @param  array<int, string>  $subjects
     * @return array<int, string>
     */
    public static function readFor(array $subjects): array
    {
        $permissions = [];

        foreach ($subjects as $subject) {
            $permissions[] = "view_any_{$subject}";
            $permissions[] = "view_{$subject}";
        }

        return $permissions;
    }

    private static function describeAction(string $action, string $noun): string
    {
        return match ($action) {
            'view_any' => "See {$noun}",
            'view' => "Open one of {$noun}",
            'create' => "Add {$noun}",
            'update' => "Change {$noun}",
            'delete' => "Delete {$noun}",
            default => "{$action} {$noun}",
        };
    }
}
