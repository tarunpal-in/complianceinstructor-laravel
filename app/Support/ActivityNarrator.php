<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Resources\Resource;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns a row of `activity_log` into something a person can read.
 *
 * Thirteen models log, each under its own name (see the map below), and the
 * raw row is a JSON property bag plus a morph. §9 makes this the audit trail
 * for every mutation — which is only true if somebody can actually read it, and
 * "updated · App\Models\Certificate · 42" is not reading it.
 *
 * The per-record History panels on Certificates, Comments, Inquiries,
 * Subscribers and Staff stay as they are. Each knows its own model well enough
 * to say "Marked as answered" where this can only say "Changed status", and
 * that difference is worth keeping. This is the cross-cutting view: everything,
 * everywhere, filterable, for the question that starts "who changed…".
 */
final class ActivityNarrator
{
    /**
     * Each log name, what to call it, and which part of the business it is.
     *
     * The group is what makes the screen usable. The audit questions this
     * business will actually be asked are "who touched money" and "who changed
     * somebody's access", and neither is answerable by scrolling one list of
     * everything.
     *
     * `identity` names the attribute that identifies a record where the usual
     * order would pick the wrong one — a mailing-list entry is its address,
     * not the optional name beside it, and a certificate is its serial.
     *
     * @var array<string, array{label: string, group: string, resource: class-string<resource>|null, identity?: string}>
     */
    public const KINDS = [
        'payment' => ['label' => 'Payment', 'group' => 'Money', 'resource' => \App\Filament\Resources\Payments\PaymentResource::class],
        'refund' => ['label' => 'Refund', 'group' => 'Money', 'resource' => null],
        'coupon' => ['label' => 'Coupon', 'group' => 'Money', 'resource' => \App\Filament\Resources\Coupons\CouponResource::class],
        'membership_plan' => ['label' => 'Membership plan', 'group' => 'Money', 'resource' => \App\Filament\Resources\MembershipPlans\MembershipPlanResource::class],

        'staff' => ['label' => 'Staff account', 'group' => 'Access', 'resource' => \App\Filament\Resources\Staff\StaffResource::class, 'identity' => 'name'],
        'entitlement' => ['label' => 'Access grant', 'group' => 'Access', 'resource' => \App\Filament\Resources\Entitlements\EntitlementResource::class],
        'certificate' => ['label' => 'Certificate', 'group' => 'Access', 'resource' => \App\Filament\Resources\Certificates\CertificateResource::class, 'identity' => 'serial'],

        'page' => ['label' => 'Page', 'group' => 'Content', 'resource' => \App\Filament\Resources\Pages\PageResource::class],
        'post' => ['label' => 'Article', 'group' => 'Content', 'resource' => \App\Filament\Resources\Posts\PostResource::class],
        'comment' => ['label' => 'Comment', 'group' => 'Content', 'resource' => \App\Filament\Resources\Comments\CommentResource::class],
        'media' => ['label' => 'Media file', 'group' => 'Content', 'resource' => \App\Filament\Resources\Media\MediaResource::class],

        'inquiry' => ['label' => 'Inquiry', 'group' => 'Inbox', 'resource' => \App\Filament\Resources\Inquiries\InquiryResource::class],
        'subscriber' => ['label' => 'Mailing list', 'group' => 'Inbox', 'resource' => \App\Filament\Resources\Subscribers\SubscriberResource::class, 'identity' => 'email'],
    ];

    /** The groups, in the order the screen offers them. */
    public const GROUPS = ['Money', 'Access', 'Content', 'Inbox'];

    /** What kind of thing was touched. */
    public static function kind(Activity $activity): string
    {
        $name = (string) $activity->log_name;

        return self::KINDS[$name]['label']
            // A log name with no entry here is a model somebody added
            // LogsActivity to without telling this map. Better to show its own
            // name than to drop the row out of the screen entirely.
            ?? Str::of($name)->replace('_', ' ')->ucfirst()->value();
    }

    public static function group(Activity $activity): ?string
    {
        return self::KINDS[(string) $activity->log_name]['group'] ?? null;
    }

    /** @return array<int, string> */
    public static function logNamesInGroup(string $group): array
    {
        return array_keys(array_filter(
            self::KINDS,
            fn (array $kind): bool => $kind['group'] === $group,
        ));
    }

    /**
     * What happened, in a sentence.
     *
     * Creation and deletion are stated plainly rather than described through
     * the property bag. On a create every logged attribute is present, so
     * listing them reads as a row of changes that never happened — the bug the
     * inquiries History panel shipped with.
     */
    public static function describe(Activity $activity): string
    {
        $kind = self::kind($activity);

        return match ($activity->event) {
            'created' => "{$kind} created",
            'deleted' => "{$kind} deleted",
            'restored' => "{$kind} restored",
            default => self::describeUpdate($activity, $kind),
        };
    }

    /**
     * What actually changed, before and after.
     *
     * @return array<int, array{attribute: string, from: string, to: string}>
     */
    public static function changes(Activity $activity): array
    {
        $after = self::bag($activity, 'attributes');
        $before = self::bag($activity, 'old');

        $changes = [];

        foreach ($after as $attribute => $value) {
            $was = $before[$attribute] ?? null;

            // logOnlyDirty should already have filtered these, but an activity
            // written by a forceFill that set a column to what it already held
            // would otherwise read as a change nobody made.
            if ($was === $value && $activity->event === 'updated') {
                continue;
            }

            $changes[] = [
                'attribute' => self::humanise($attribute),
                'from' => self::value($was),
                'to' => self::value($value),
            ];
        }

        return $changes;
    }

    /**
     * The record this happened to, if it still exists and can be opened.
     *
     * Null covers three ordinary cases: a log name with no screen (a refund is
     * read on its payment), a record since deleted, and somebody without
     * permission to open that resource — an audit trail must not become a way
     * round the permissions on everything else.
     */
    public static function recordUrl(Activity $activity): ?string
    {
        $resource = self::KINDS[(string) $activity->log_name]['resource'] ?? null;

        if ($resource === null || $activity->subject === null || ! $resource::canViewAny()) {
            return null;
        }

        $pages = array_keys($resource::getPages());

        foreach (['view', 'edit'] as $page) {
            if (in_array($page, $pages, true)) {
                return $resource::getUrl($page, ['record' => $activity->subject]);
            }
        }

        return $resource::getUrl('index');
    }

    /** Which record, named rather than numbered where the model can say. */
    public static function recordLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        if ($subject === null) {
            // Deleted since, or a subject whose table has been emptied. The id
            // is still worth printing — it is what an export or a backup would
            // be searched on.
            return self::kind($activity).' #'.($activity->subject_id ?? '?');
        }

        $preferred = self::KINDS[(string) $activity->log_name]['identity'] ?? null;

        foreach (array_filter([$preferred, 'name', 'title', 'code', 'email', 'number', 'serial']) as $attribute) {
            $value = $subject->getAttributes()[$attribute] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit($value, 60);
            }
        }

        return self::kind($activity).' #'.$subject->getKey();
    }

    /** Who did it. */
    public static function causerName(Activity $activity): string
    {
        $causer = $activity->causer;

        if ($causer === null) {
            // Nobody was signed in: a scheduled sweep, a console command, a
            // seeder, or the person themselves through a public form.
            return 'The system';
        }

        $name = $causer->getAttributes()['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? $name : 'Account #'.$causer->getKey();
    }

    private static function describeUpdate(Activity $activity, string $kind): string
    {
        $changes = self::changes($activity);

        if ($changes === []) {
            return "{$kind} saved with no visible change";
        }

        $attributes = array_map(
            fn (array $change): string => Str::lower($change['attribute']),
            $changes,
        );

        return $kind.' — changed '.self::list($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bag(Activity $activity, string $key): array
    {
        $bag = data_get($activity->properties, $key, []);

        return is_array($bag) ? $bag : [];
    }

    private static function humanise(string $attribute): string
    {
        return Str::of($attribute)
            // `deactivated_by` and friends hold an id nobody reads as one.
            ->replace('_id', '')
            ->replace('_at', '')
            ->replace('_', ' ')
            ->ucfirst()
            ->value();
    }

    /** A property-bag value as something printable. */
    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => 'nothing',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => Str::limit(json_encode($value) ?: '', 80),
            default => Str::limit((string) $value, 80),
        };
    }

    /**
     * "a, b and c" — because "changed status, note" reads as a truncated list.
     *
     * @param  array<int, string>  $items
     */
    private static function list(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
