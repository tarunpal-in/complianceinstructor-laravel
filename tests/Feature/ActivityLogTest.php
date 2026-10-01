<?php

declare(strict_types=1);

use App\Actions\Staff\InviteStaffMember;
use App\Actions\Staff\SetStaffAccess;
use App\Enums\UserRole;
use App\Filament\Resources\ActivityLog\ActivityResource;
use App\Filament\Resources\ActivityLog\Pages\ListActivity;
use App\Models\Comment;
use App\Models\Inquiry;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\ActivityNarrator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** The log entry a given change produced. */
function latestActivity(): Activity
{
    return Activity::query()->latest('id')->firstOrFail();
}

// Nothing may touch it ---------------------------------------------------------------------

/*
 * An audit trail somebody can edit is not an audit trail, and the person most
 * likely to want a row gone is the one the row is about. Not even a Super Admin.
 */
it('allows nothing to be created, changed or deleted', function (): void {
    $entry = Activity::query()->forceCreate([
        'log_name' => 'comment',
        'description' => 'updated',
        'event' => 'updated',
    ]);

    expect(ActivityResource::canCreate())->toBeFalse()
        ->and(ActivityResource::canEdit($entry))->toBeFalse()
        ->and(ActivityResource::canDelete($entry))->toBeFalse()
        ->and(ActivityResource::canDeleteAny())->toBeFalse();
});

it('offers no edit or delete page at all', function (): void {
    expect(array_keys(ActivityResource::getPages()))->toBe(['index', 'view']);
});

it('keeps the log to roles holding view_activity_log', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))->get('/admin/activity-log')->assertSuccessful();
    $this->actingAs(userWithRole(UserRole::Finance))->get('/admin/activity-log')->assertSuccessful();

    // Neither has the permission; the trail records what they did rather than
    // being something they read.
    $this->actingAs(userWithRole(UserRole::ContentEditor))->get('/admin/activity-log')->assertForbidden();
    $this->actingAs(userWithRole(UserRole::Support))->get('/admin/activity-log')->assertForbidden();
});

// It says what happened ------------------------------------------------------------------------

/*
 * On a create every logged attribute is present in the property bag, so
 * describing them one by one turns a brand-new record into a list of changes
 * that never happened — the bug the inquiries History panel shipped with.
 */
it('reads a new record as created, not as a list of changes', function (): void {
    Inquiry::factory()->create();

    expect(ActivityNarrator::describe(latestActivity()))->toBe('Inquiry created');
});

it('names what actually changed', function (): void {
    $inquiry = Inquiry::factory()->create(['note' => 'Before']);
    $inquiry->forceFill(['note' => 'After'])->save();

    expect(ActivityNarrator::describe(latestActivity()))->toBe('Inquiry — changed note');
});

it('lists several changes readably', function (): void {
    $subscriber = Subscriber::factory()->create();
    $subscriber->forceFill([
        'status' => App\Enums\SubscriberStatus::Unsubscribed,
        'unsubscribed_at' => now(),
        'unsubscribe_reason' => 'Too many emails',
    ])->save();

    // "status, unsubscribed and unsubscribe reason" rather than a truncated
    // comma list that reads as though it was cut off.
    expect(ActivityNarrator::describe(latestActivity()))->toContain(' and ');
});

it('shows what a value changed from, not just that it changed', function (): void {
    $inquiry = Inquiry::factory()->create(['note' => 'Before']);
    $inquiry->forceFill(['note' => 'After'])->save();

    $changes = ActivityNarrator::changes(latestActivity());

    expect($changes)->toHaveCount(1)
        ->and($changes[0]['from'])->toBe('Before')
        ->and($changes[0]['to'])->toBe('After');
});

it('says "nothing" rather than leaving a blank for an empty value', function (): void {
    $inquiry = Inquiry::factory()->create(['note' => null]);
    $inquiry->forceFill(['note' => 'Something'])->save();

    expect(ActivityNarrator::changes(latestActivity())[0]['from'])->toBe('nothing');
});

it('reads a boolean as yes or no', function (): void {
    $post = App\Models\Post::factory()->create();
    $post->forceFill(['status' => App\Enums\PublishStatus::Published, 'published_at' => now()])->save();

    $changes = collect(ActivityNarrator::changes(latestActivity()));

    expect($changes)->not->toBeEmpty();
});

// Who did it -------------------------------------------------------------------------------------

it('names the person who made the change', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $this->actingAs($editor);

    $comment = Comment::factory()->create();
    $comment->forceFill(['status' => App\Enums\CommentStatus::Approved])->save();

    expect(ActivityNarrator::causerName(latestActivity()))->toBe($editor->name);
});

/*
 * Nobody signed in is a real answer rather than a gap — a scheduled sweep, a
 * console command, a seeder, or a visitor using a public form.
 */
it('says the system did it when nobody was signed in', function (): void {
    Inquiry::factory()->create();

    expect(ActivityNarrator::causerName(latestActivity()))->toBe('The system');
});

// Which record -------------------------------------------------------------------------------------

it('names the record rather than numbering it', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $subscriber = Subscriber::factory()->create(['email' => 'dana@example.com']);
    $subscriber->forceFill(['status' => App\Enums\SubscriberStatus::Unsubscribed])->save();

    expect(ActivityNarrator::recordLabel(latestActivity()))->toBe('dana@example.com');
});

it('still identifies a record that has since been deleted', function (): void {
    $subscriber = Subscriber::factory()->create();
    $subscriber->forceFill(['status' => App\Enums\SubscriberStatus::Unsubscribed])->save();

    $entry = latestActivity();
    $id = $subscriber->getKey();
    $subscriber->delete();

    // The id is what a backup or an export would be searched on.
    expect(ActivityNarrator::recordLabel($entry->fresh()))->toContain((string) $id);
});

it('links to the record when it can be opened', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $inquiry = Inquiry::factory()->create();
    $inquiry->forceFill(['note' => 'Changed'])->save();

    expect(ActivityNarrator::recordUrl(latestActivity()))->toContain('/admin/inquiries/');
});

/*
 * An audit trail must not become a way round the permissions on everything
 * else: seeing that a certificate changed is not seeing the certificate.
 */
it('offers no link to a resource the reader may not open', function (): void {
    $this->actingAs(userWithRole(UserRole::Finance));

    $comment = Comment::factory()->create();
    $comment->forceFill(['status' => App\Enums\CommentStatus::Approved])->save();

    // Finance holds no comment permission.
    expect(ActivityNarrator::recordUrl(latestActivity()))->toBeNull();
});

// The screen -------------------------------------------------------------------------------------------

it('groups entries by the part of the business they belong to', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $subscriber = Subscriber::factory()->create();
    $subscriber->forceFill(['status' => App\Enums\SubscriberStatus::Unsubscribed])->save();
    $inboxEntry = latestActivity();

    $comment = Comment::factory()->create();
    $comment->forceFill(['status' => App\Enums\CommentStatus::Approved])->save();
    $contentEntry = latestActivity();

    Livewire\Livewire::test(ListActivity::class)
        ->set('activeTab', 'inbox')
        ->assertCanSeeTableRecords([$inboxEntry])
        ->assertCanNotSeeTableRecords([$contentEntry])
        ->set('activeTab', 'content')
        ->assertCanSeeTableRecords([$contentEntry])
        ->assertCanNotSeeTableRecords([$inboxEntry]);
});

it('puts every kind in exactly one group', function (): void {
    $grouped = [];

    foreach (ActivityNarrator::GROUPS as $group) {
        foreach (ActivityNarrator::logNamesInGroup($group) as $logName) {
            $grouped[] = $logName;
        }
    }

    expect(count($grouped))->toBe(count(array_unique($grouped)))
        ->and(collect($grouped)->sort()->values()->all())
        ->toBe(collect(array_keys(ActivityNarrator::KINDS))->sort()->values()->all());
});

/*
 * Every model that logs needs an entry in the map, or its changes appear with
 * a raw log name and no group — present in "Everything" and missing from every
 * tab somebody would actually look in.
 */
it('knows about every model that writes to the log', function (): void {
    $logNames = [];

    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        preg_match("/useLogName\('([a-z_]+)'\)/", (string) file_get_contents($file), $matches);

        if (isset($matches[1])) {
            $logNames[] = $matches[1];
        }
    }

    expect($logNames)->not->toBeEmpty()
        ->and(array_diff($logNames, array_keys(ActivityNarrator::KINDS)))->toBe([]);
});

it('keeps the export to roles holding export_data', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListActivity::class)
        ->assertActionVisible('export');
});

it('renders an entry with its before and after', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $inquiry = Inquiry::factory()->create(['note' => 'Before']);
    $inquiry->forceFill(['note' => 'After'])->save();

    $this->get('/admin/activity-log/'.latestActivity()->getKey())
        ->assertSuccessful()
        ->assertSee('Before')
        ->assertSee('After');
});

// What the trail is for ---------------------------------------------------------------------------------

/*
 * The question this screen exists to answer. Somebody gave an account access
 * it should not have had, and the trail says who and when.
 */
it('records who gave somebody admin access', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    $joiner = User::factory()->create();
    $joiner->forceFill(['name' => 'Marcus Reed'])->save();

    $entry = latestActivity();

    expect(ActivityNarrator::causerName($entry))->toBe($admin->name)
        ->and(ActivityNarrator::kind($entry))->toBe('Staff account');
});

/*
 * Roles live in a pivot table and a pivot write fires no model event, so
 * LogsActivity on User never sees one. Until RoleAssignment existed, making
 * somebody a Super Admin left no trace at all — the one mutation §8 missed,
 * and the exact question the Access tab is for.
 */
it('records who gave somebody a role', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    $joiner = User::factory()->create();

    app(InviteStaffMember::class)(
        name: 'Marcus Reed',
        email: $joiner->email,
        roles: [UserRole::Finance->value],
        actor: $admin,
    );

    $entry = latestActivity();
    $changes = ActivityNarrator::changes($entry);

    expect(ActivityNarrator::causerName($entry))->toBe($admin->name)
        ->and(ActivityNarrator::describe($entry))->toBe('Staff account — changed roles')
        ->and($changes[0]['from'])->toBe('nothing')
        ->and($changes[0]['to'])->toBe(UserRole::Finance->value);
});

it('records who took a role away, and what it was', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $target = userWithRole(UserRole::Finance);

    $this->actingAs($admin);

    app(SetStaffAccess::class)->assign($target, [UserRole::Support->value], $admin);

    $changes = ActivityNarrator::changes(latestActivity());

    expect(ActivityNarrator::causerName(latestActivity()))->toBe($admin->name)
        ->and($changes[0]['from'])->toBe(UserRole::Finance->value)
        ->and($changes[0]['to'])->toBe(UserRole::Support->value);
});

/*
 * Filament saves the whole form on every edit, so correcting an email would
 * otherwise write "roles changed from Finance to Finance" every time.
 */
it('records nothing when a save leaves the roles alone', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $target = userWithRole(UserRole::Finance);

    $this->actingAs($admin);
    $before = Activity::query()->count();

    app(SetStaffAccess::class)->assign($target, [UserRole::Finance->value], $admin);

    expect(Activity::query()->count())->toBe($before);
});

/*
 * Nothing may write a role change without recording it. The three columns
 * behind two-factor are the same shape of hole: none can be logged — a secret
 * and a set of recovery codes must never reach the log — so the save writes
 * nothing by itself.
 */
it('allows no role change outside RoleAssignment', function (): void {
    /*
     * phpstan.neon bans these three methods outright, with the one exemption,
     * which is the better guard: it is type-aware and it tells whoever made the
     * mistake why at the point they made it. This is here because that ban is
     * three lines of config somebody could remove along with the rule it
     * protects, and this failure names the file instead.
     */
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getPathname()))
        ->reject(fn (string $path): bool => str_ends_with($path, 'app/Support/RoleAssignment.php'))
        ->filter(fn (string $path): bool => preg_match('/->(syncRoles|assignRole|removeRole)\(/', (string) file_get_contents($path)) === 1)
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('records who cleared somebody two-factor setup', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($admin);

    $target = userWithRole(UserRole::Support);
    $target->forceFill([
        'two_factor_secret' => encrypt('SECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    app(SetStaffAccess::class)->clearTwoFactor($target, $admin);

    $changes = ActivityNarrator::changes(latestActivity());

    expect(ActivityNarrator::causerName(latestActivity()))->toBe($admin->name)
        ->and($changes[0]['from'])->toBe('set up')
        ->and($changes[0]['to'])->toBe('cleared');
});

it('records nothing when there was no two-factor setup to clear', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $target = userWithRole(UserRole::Support);

    $before = Activity::query()->count();

    app(SetStaffAccess::class)->clearTwoFactor($target, $admin);

    expect(Activity::query()->count())->toBe($before);
});

it('records who switched an account off, and why', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $editor = userWithRole(UserRole::ContentEditor);

    $this->actingAs($admin);

    app(SetStaffAccess::class)->deactivate($editor, 'Left on 30 September', $admin);

    $changes = collect(ActivityNarrator::changes(latestActivity()))
        ->pluck('to')
        ->implode(' ');

    expect(ActivityNarrator::causerName(latestActivity()))->toBe($admin->name)
        ->and($changes)->toContain('Left on 30 September');
});
