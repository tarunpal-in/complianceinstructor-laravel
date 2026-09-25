<?php

declare(strict_types=1);

use App\Actions\Subscribers\Subscribe;
use App\Actions\Subscribers\Unsubscribe;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Subscribers\Pages\CreateSubscriber;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Resources\Subscribers\Pages\ViewSubscriber;
use App\Http\Requests\StoreSubscriberRequest;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Support\SubscriberCsv;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** Posting the signup box in the site footer, honeypot left alone. */
function joinList(array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->post('/newsletter', ['email' => 'dana@example.com', ...$overrides]);
}

// Joining ----------------------------------------------------------------------------

it('puts somebody on the list from the footer box', function (): void {
    joinList()->assertRedirect();

    $subscriber = Subscriber::query()->sole();

    expect($subscriber->email)->toBe('dana@example.com')
        ->and($subscriber->status)->toBe(SubscriberStatus::Subscribed)
        ->and($subscriber->source)->toBe(SubscriberSource::Footer)
        ->and($subscriber->subscribed_at)->not->toBeNull();
});

it('records what it could see of them when they agreed', function (): void {
    joinList();

    $subscriber = Subscriber::query()->sole();

    expect($subscriber->consent_ip)->not->toBeNull()
        ->and($subscriber->source)->toBe(SubscriberSource::Footer);
});

it('lower-cases the address so the unique index can do its job', function (): void {
    joinList(['email' => '  DANA@Example.COM ']);

    expect(Subscriber::query()->sole()->email)->toBe('dana@example.com');
});

/*
 * The opposite decision from `inquiries`, where a second message from one
 * address is deliberately a second row. Here a second row is two copies of
 * every campaign landing in one inbox.
 */
it('does not create a second row when somebody signs up twice', function (): void {
    joinList();
    joinList();

    expect(Subscriber::query()->count())->toBe(1);
});

it('keeps the original consent date when an existing subscriber signs up again', function (): void {
    $original = Subscriber::factory()->withAddress('dana@example.com')->create([
        'subscribed_at' => now()->subYear(),
    ]);

    joinList();

    expect($original->fresh()?->subscribed_at?->toDateString())
        ->toBe(now()->subYear()->toDateString());
});

it('puts somebody who unsubscribed back on if they ask again', function (): void {
    Subscriber::factory()->withAddress('dana@example.com')->unsubscribed()->create();

    joinList();

    $subscriber = Subscriber::query()->sole();

    expect($subscriber->status)->toBe(SubscriberStatus::Subscribed)
        ->and($subscriber->unsubscribed_at)->toBeNull();
});

/*
 * The rule that protects the sending domain. They told a mail provider we were
 * spam; a form field is nowhere near enough evidence to overturn that.
 */
it('will not put a spam complainant back on from the signup box', function (): void {
    Subscriber::factory()->withAddress('dana@example.com')->complained()->create();

    joinList();

    expect(Subscriber::query()->sole()->status)->toBe(SubscriberStatus::Complained);
});

/** But staff can, deliberately — that is what the admin action is for. */
it('lets staff put a spam complainant back on', function (): void {
    $subscriber = Subscriber::factory()->complained()->create();

    app(Subscribe::class)(
        email: $subscriber->email,
        source: SubscriberSource::Manual,
        asStaff: true,
    );

    expect($subscriber->fresh()?->status)->toBe(SubscriberStatus::Subscribed);
});

it('tells everybody the same thing, whatever happened', function (): void {
    Subscriber::factory()->withAddress('dana@example.com')->create();

    // An address already on the list must not be distinguishable from a new
    // one, or the footer becomes a way of testing who is a customer here.
    joinList()->assertSessionHas('mailing_list_status', 'Thanks. You are on the list.');
});

// The obvious ways in -----------------------------------------------------------------

it('drops a signup that filled in the honeypot', function (): void {
    joinList([StoreSubscriberRequest::TRAP_FIELD => 'http://spam.test'])
        ->assertSessionHasErrors(StoreSubscriberRequest::TRAP_FIELD);

    expect(Subscriber::query()->count())->toBe(0);
});

it('refuses an address that is not one', function (): void {
    joinList(['email' => 'not-an-address'])->assertSessionHasErrors('email');

    expect(Subscriber::query()->count())->toBe(0);
});

/** §14 #2 — where a signup lands is never decided by the request. */
it('ignores a status posted with the form', function (): void {
    joinList(['status' => SubscriberStatus::Complained->value]);

    expect(Subscriber::query()->sole()->status)->toBe(SubscriberStatus::Subscribed);
});

// Double opt-in -----------------------------------------------------------------------

it('holds a signup for confirmation when double opt-in is on', function (): void {
    Setting::set('mailing_list.double_opt_in', true, 'boolean');

    joinList();

    expect(Subscriber::query()->sole()->status)->toBe(SubscriberStatus::Pending);
});

it('confirms through the signed link', function (): void {
    $subscriber = Subscriber::factory()->pending()->create();

    $this->get($subscriber->confirmUrl())->assertSuccessful();

    $subscriber->refresh();

    expect($subscriber->status)->toBe(SubscriberStatus::Subscribed)
        ->and($subscriber->confirmed_at)->not->toBeNull();
});

it('refuses an unsigned confirmation link', function (): void {
    $subscriber = Subscriber::factory()->pending()->create();

    $this->get('/mailing-list/confirm/'.$subscriber->getKey())->assertForbidden();
});

/** The later decision stands: an old confirm link must not undo an unsubscribe. */
it('does not resurrect an unsubscribed address through an old confirm link', function (): void {
    $subscriber = Subscriber::factory()->pending()->create();
    $url = $subscriber->confirmUrl();

    app(Unsubscribe::class)($subscriber);

    $this->get($url);

    expect($subscriber->fresh()?->status)->toBe(SubscriberStatus::Unsubscribed);
});

// Leaving --------------------------------------------------------------------------------

/*
 * Mail scanners and link-preview services fetch every URL in an email. A GET
 * that acted would take people off the list who never clicked anything.
 */
it('does not unsubscribe anybody just by opening the link', function (): void {
    $subscriber = Subscriber::factory()->create();

    $this->get($subscriber->unsubscribeUrl())
        ->assertSuccessful()
        ->assertSee('Yes, unsubscribe me');

    expect($subscriber->fresh()?->status)->toBe(SubscriberStatus::Subscribed);
});

it('unsubscribes when the button is pressed', function (): void {
    $subscriber = Subscriber::factory()->create();

    $this->post(url()->signedRoute('mailing-list.unsubscribe.confirm', ['subscriber' => $subscriber->getKey()]))
        ->assertSuccessful();

    $subscriber->refresh();

    expect($subscriber->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($subscriber->unsubscribed_at)->not->toBeNull();
});

it('refuses an unsigned unsubscribe link', function (): void {
    $subscriber = Subscriber::factory()->create();

    $this->get('/mailing-list/unsubscribe/'.$subscriber->getKey())->assertForbidden();
});

/** The link lives in every email ever sent, so it will be clicked twice. */
it('is unbothered by unsubscribing twice', function (): void {
    $subscriber = Subscriber::factory()->unsubscribed('Asked by email')->create();
    $when = $subscriber->unsubscribed_at;

    app(Unsubscribe::class)($subscriber, 'A different reason');

    expect($subscriber->fresh()?->unsubscribe_reason)->toBe('Asked by email')
        ->and($subscriber->fresh()?->unsubscribed_at?->toDateTimeString())->toBe($when?->toDateTimeString());
});

/*
 * The single most important rule on this table: the row IS the suppression.
 * Unsubscribing that deleted the row would let the next import put them back.
 */
it('keeps the row when somebody unsubscribes', function (): void {
    $subscriber = Subscriber::factory()->create();

    app(Unsubscribe::class)($subscriber);

    expect(Subscriber::query()->whereKey($subscriber->getKey())->exists())->toBeTrue();
});

// Who may be mailed --------------------------------------------------------------------------

it('offers exactly one definition of who may be mailed', function (): void {
    Subscriber::factory()->count(2)->create();
    Subscriber::factory()->pending()->create();
    Subscriber::factory()->unsubscribed()->create();
    Subscriber::factory()->bounced()->create();
    Subscriber::factory()->complained()->create();

    expect(Subscriber::query()->mailable()->count())->toBe(2)
        ->and(Subscriber::query()->suppressed()->count())->toBe(3);
});

it('keeps a pending signup out of the mailable list', function (): void {
    $subscriber = Subscriber::factory()->pending()->create();

    expect($subscriber->isMailable())->toBeFalse()
        // Not suppressed either — nobody refused anything, it is just unproven.
        ->and($subscriber->isSuppressed())->toBeFalse();
});

// The admin screen ------------------------------------------------------------------------------

it('opens the list to roles that may see it', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/subscribers')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::ContentEditor, UserRole::Support, UserRole::Finance]);

it('lets only the right roles change the list', function (): void {
    expect(userWithRole(UserRole::ContentEditor)->can('update_subscriber'))->toBeTrue()
        ->and(userWithRole(UserRole::Support)->can('update_subscriber'))->toBeTrue()
        // Support answers "take me off your list"; it does not add addresses.
        ->and(userWithRole(UserRole::Support)->can('create_subscriber'))->toBeFalse()
        ->and(userWithRole(UserRole::Finance)->can('update_subscriber'))->toBeFalse();
});

it('opens on the people who are actually on the list', function (): void {
    $on = Subscriber::factory()->create();
    $off = Subscriber::factory()->unsubscribed()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(ListSubscribers::class)
        ->assertCanSeeTableRecords([$on])
        ->assertCanNotSeeTableRecords([$off])
        ->set('activeTab', 'unsubscribed')
        ->assertCanSeeTableRecords([$off])
        ->assertCanNotSeeTableRecords([$on]);
});

it('groups bounces and complaints as problems', function (): void {
    $bounced = Subscriber::factory()->bounced()->create();
    $complained = Subscriber::factory()->complained()->create();
    $ordinary = Subscriber::factory()->unsubscribed()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(ListSubscribers::class)
        ->set('activeTab', 'problems')
        ->assertCanSeeTableRecords([$bounced, $complained])
        ->assertCanNotSeeTableRecords([$ordinary]);
});

it('unsubscribes from the list with a reason', function (): void {
    $subscriber = Subscriber::factory()->create();

    $this->actingAs(userWithRole(UserRole::Support));

    Livewire\Livewire::test(ListSubscribers::class)
        ->callTableAction('unsubscribe', $subscriber, ['reason' => 'Asked by email']);

    expect($subscriber->fresh()?->unsubscribe_reason)->toBe('Asked by email');
});

it('adds an address by hand as added by staff', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateSubscriber::class)
        ->fillForm(['email' => 'typed@example.com', 'source' => SubscriberSource::Manual->value])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Subscriber::query()->where('email', 'typed@example.com')->sole()->source)
        ->toBe(SubscriberSource::Manual);
});

/**
 * No edit page: every value on this table is a decision with a date attached,
 * and a form over them would let somebody type a confirmation date for a
 * confirmation that never happened.
 */
it('offers no way to edit a consent record by hand', function (): void {
    expect(App\Filament\Resources\Subscribers\SubscriberResource::getPages())
        ->not->toHaveKey('edit');
});

it('records who took an address off the list', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);
    $subscriber = Subscriber::factory()->create();

    $this->actingAs($editor);

    Livewire\Livewire::test(ViewSubscriber::class, ['record' => $subscriber->getKey()])
        ->callAction('unsubscribe', ['reason' => 'Asked at a conference']);

    expect($subscriber->fresh()?->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($subscriber->activities()->exists())->toBeTrue();
});

// Export ----------------------------------------------------------------------------------------

it('keeps the export to roles holding export_data', function (): void {
    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListSubscribers::class)
        ->assertActionVisible('export');

    // Content Editor runs the list without being able to walk away with it.
    Livewire\Livewire::actingAs(userWithRole(UserRole::ContentEditor))
        ->test(ListSubscribers::class)
        ->assertActionHidden('export');
});

it('exports the consent alongside the address', function (): void {
    Subscriber::factory()->confirmed()->create(['email' => 'dana@example.com']);

    $handle = fopen('php://memory', 'r+b');
    SubscriberCsv::writeTo($handle, Subscriber::query()->mailable());
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    expect($csv)->toContain('dana@example.com')
        // The file becomes the only record of why these addresses are on a
        // list the moment it leaves the system.
        ->toContain('Site footer')
        ->and(str_getcsv(explode("\n", $csv)[0]))->toBe(SubscriberCsv::headers());
});

it('does not export the suppressed when the mailable tab is showing', function (): void {
    Subscriber::factory()->create(['email' => 'on@example.com']);
    Subscriber::factory()->unsubscribed()->create(['email' => 'off@example.com']);

    $handle = fopen('php://memory', 'r+b');
    SubscriberCsv::writeTo($handle, Subscriber::query()->mailable());
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    expect($csv)->toContain('on@example.com')->not->toContain('off@example.com');
});

// Seed data is a deliverable (§5.9) ------------------------------------------------------------------

it('seeds a list with every state in it', function (): void {
    $this->seed(Database\Seeders\SubscriberSeeder::class);

    expect(Subscriber::query()->mailable()->count())->toBeGreaterThan(0)
        ->and(Subscriber::query()->awaitingConfirmation()->count())->toBeGreaterThan(0)
        ->and(Subscriber::query()->problems()->count())->toBeGreaterThan(0)
        ->and(Subscriber::query()->where('status', SubscriberStatus::Unsubscribed)->count())->toBeGreaterThan(0);
});

it('leaves the mailing list alone on a second seed', function (): void {
    $this->seed(Database\Seeders\SubscriberSeeder::class);
    $count = Subscriber::query()->count();

    $this->seed(Database\Seeders\SubscriberSeeder::class);

    expect(Subscriber::query()->count())->toBe($count);
});

it('does not let a seeded customer address duplicate', function (): void {
    User::factory()->create(['email' => 'buyer@example.com']);

    $this->seed(Database\Seeders\SubscriberSeeder::class);

    expect(Subscriber::query()->where('email', 'buyer@example.com')->count())
        ->toBeLessThanOrEqual(1);
});
