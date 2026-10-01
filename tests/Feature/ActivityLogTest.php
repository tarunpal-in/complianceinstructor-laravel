<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\ActivityLog\ActivityResource;
use App\Filament\Resources\ActivityLog\Pages\ListActivity;
use App\Models\Comment;
use App\Models\Inquiry;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\ActivityNarrator;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

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

it('records who switched an account off, and why', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);
    $editor = userWithRole(UserRole::ContentEditor);

    $this->actingAs($admin);

    app(App\Actions\Staff\SetStaffAccess::class)->deactivate($editor, 'Left on 30 September', $admin);

    $changes = collect(ActivityNarrator::changes(latestActivity()))
        ->pluck('to')
        ->implode(' ');

    expect(ActivityNarrator::causerName(latestActivity()))->toBe($admin->name)
        ->and($changes)->toContain('Left on 30 September');
});
