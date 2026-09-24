<?php

declare(strict_types=1);

use App\Actions\Inquiries\RecordInquiry;
use App\Enums\InquirySource;
use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Inquiries\Pages\CreateInquiry;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Inquiries\Pages\ViewInquiry;
use App\Models\Inquiry;
use App\Models\Order;
use App\Models\User;
use App\Support\InquiryCsv;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A customer who has actually paid for something. */
function buyer(string $email = 'buyer@example.com'): User
{
    $user = User::factory()->create(['email' => $email]);

    Order::factory()->for($user)->paid()->create();

    return $user;
}

// Authorization -------------------------------------------------------------------

it('opens the register to roles that work inquiries', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/inquiries')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Support, UserRole::Finance, UserRole::ContentEditor]);

/*
 * The split that matters: Support rings these people, Finance and the content
 * editor only look. A register everybody can rewrite is one where nobody can
 * tell what was agreed with whom.
 */
it('lets only Support and the Super Admin work an inquiry', function (): void {
    $inquiry = Inquiry::factory()->create();

    expect(userWithRole(UserRole::Support)->can('update_inquiry'))->toBeTrue()
        ->and(userWithRole(UserRole::SuperAdmin)->can('update_inquiry'))->toBeTrue()
        ->and(userWithRole(UserRole::Finance)->can('update_inquiry'))->toBeFalse()
        ->and(userWithRole(UserRole::ContentEditor)->can('update_inquiry'))->toBeFalse()
        ->and($inquiry->exists)->toBeTrue();
});

/** Reading an inquiry and taking the whole list off the system are different things. */
it('keeps the export to roles holding export_data', function (): void {
    Inquiry::factory()->count(2)->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListInquiries::class)
        ->assertActionVisible('export');

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListInquiries::class)
        ->assertActionHidden('export');
});

// Intake ------------------------------------------------------------------------------

it('records an inquiry through the action', function (): void {
    $inquiry = app(RecordInquiry::class)(
        name: '  Dana Whitfield ',
        email: '  DANA@Example.COM ',
        source: InquirySource::Quiz,
        company: 'Northwind',
        sourceDetail: 'Which HR gaps are costing you?',
        marketingOptIn: true,
    );

    expect($inquiry->email)->toBe('dana@example.com')
        ->and($inquiry->name)->toBe('Dana Whitfield')
        ->and($inquiry->status)->toBe(InquiryStatus::New)
        ->and($inquiry->marketing_opt_in)->toBeTrue();
});

/** §14 #2, in a smaller corner: a form does not get to say it is qualified. */
it('always starts an inquiry at new', function (): void {
    $inquiry = app(RecordInquiry::class)(
        name: 'Dana',
        email: 'dana@example.com',
        source: InquirySource::Contact,
    );

    expect($inquiry->status)->toBe(InquiryStatus::New);
});

/** An imported list did not agree to anything, whatever the spreadsheet said. */
it('refuses to record consent for a source nobody submitted', function (): void {
    $inquiry = app(RecordInquiry::class)(
        name: 'Dana',
        email: 'dana@example.com',
        source: InquirySource::Import,
        marketingOptIn: true,
    );

    expect($inquiry->marketing_opt_in)->toBeFalse();
});

it('refuses an inquiry with no name or email', function (): void {
    expect(fn () => app(RecordInquiry::class)('  ', 'dana@example.com', InquirySource::Manual))
        ->toThrow(RuntimeException::class);
});

it('carries the campaign that produced them', function (): void {
    $request = Request::create('/quiz?utm_source=google&utm_medium=cpc&utm_campaign=hr-2026');

    $inquiry = app(RecordInquiry::class)(
        name: 'Dana',
        email: 'dana@example.com',
        source: InquirySource::Quiz,
        request: $request,
    );

    expect($inquiry->utm_source)->toBe('google')
        ->and($inquiry->utm_medium)->toBe('cpc')
        ->and($inquiry->utm_campaign)->toBe('hr-2026');
});

it('lowercases an email typed in by staff', function (): void {
    $inquiry = Inquiry::factory()->create(['email' => 'Mixed.Case@Example.COM']);

    expect($inquiry->fresh()?->email)->toBe('mixed.case@example.com');
});

it('adds an inquiry from the admin form', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateInquiry::class)
        ->fillForm([
            'name' => 'Marcus Reed',
            'email' => 'marcus@example.com',
            'source' => InquirySource::Manual->value,
            'company' => 'Cedar & Vale',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Inquiry::query()->where('email', 'marcus@example.com')->exists())->toBeTrue();
});

// The outcome is derived, never stored -------------------------------------------------

it('says an inquiry bought when their email belongs to a paying account', function (): void {
    $inquiry = Inquiry::factory()->create(['email' => buyer()->email]);

    expect($inquiry->hasBought())->toBeTrue()
        ->and($inquiry->describeOutcome())->toBe('Bought');
});

it('separates registering from buying', function (): void {
    $registered = User::factory()->create(['email' => 'registered@example.com']);
    $inquiry = Inquiry::factory()->create(['email' => $registered->email]);

    expect($inquiry->hasAccount())->toBeTrue()
        ->and($inquiry->hasBought())->toBeFalse()
        ->and($inquiry->describeOutcome())->toBe('Registered');
});

it('says nothing came of an inquiry with no account', function (): void {
    $inquiry = Inquiry::factory()->create(['email' => 'stranger@example.com']);

    expect($inquiry->describeOutcome())->toBe('Not yet');
});

/*
 * The whole argument for deriving it: nothing on the inquiry row changed, and yet
 * the answer did. A `converted` column would still be saying "no".
 */
it('notices a conversion that happened after the inquiry was captured', function (): void {
    $inquiry = Inquiry::factory()->create(['email' => 'later@example.com']);

    expect($inquiry->describeOutcome())->toBe('Not yet');

    buyer('later@example.com');

    expect($inquiry->fresh()?->describeOutcome())->toBe('Bought');
});

it('finds converted inquiries without reading a status column', function (): void {
    $converted = Inquiry::factory()->create(['email' => buyer()->email]);
    $cold = Inquiry::factory()->create(['email' => 'cold@example.com']);

    $found = Inquiry::query()->converted()->pluck('id');

    expect($found)->toContain($converted->id)
        ->not->toContain($cold->id);
});

/** A pending order is an intent, not a purchase. */
it('does not count an unpaid order as a conversion', function (): void {
    $user = User::factory()->create(['email' => 'pending@example.com']);
    Order::factory()->for($user)->pending()->create();

    expect(Inquiry::factory()->create(['email' => $user->email])->hasBought())->toBeFalse();
});

// Repeats -------------------------------------------------------------------------------

it('keeps both inquiries when the same address comes back', function (): void {
    Inquiry::factory()->create(['email' => 'priya@example.com']);
    Inquiry::factory()->create(['email' => 'priya@example.com']);

    expect(Inquiry::query()->where('email', 'priya@example.com')->count())->toBe(2);
});

it('counts every inquiry from one address', function (): void {
    $first = Inquiry::factory()->create(['email' => 'priya@example.com']);
    Inquiry::factory()->create(['email' => 'priya@example.com']);

    expect($first->fromSameEmail()->count())->toBe(2);
});

// The queue ---------------------------------------------------------------------------------

it('opens on the inquiries nobody picked up', function (): void {
    $untouched = Inquiry::factory()->untouched()->create();
    $worked = Inquiry::factory()->assignedTo(userWithRole(UserRole::Support))->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListInquiries::class)
        ->assertCanSeeTableRecords([$untouched])
        ->assertCanNotSeeTableRecords([$worked])
        ->set('activeTab', 'open')
        ->assertCanSeeTableRecords([$untouched, $worked]);
});

it('counts only unpicked inquiries in the sidebar badge', function (): void {
    Inquiry::factory()->count(3)->untouched()->create();
    Inquiry::factory()->assignedTo(userWithRole(UserRole::Support))->create();

    expect(App\Filament\Resources\Inquiries\InquiryResource::getNavigationBadge())->toBe('3');
});

it('shows no badge when everything has been picked up', function (): void {
    Inquiry::factory()->assignedTo(userWithRole(UserRole::Support))->create();

    expect(App\Filament\Resources\Inquiries\InquiryResource::getNavigationBadge())->toBeNull();
});

it('lists converted inquiries on their own tab', function (): void {
    $converted = Inquiry::factory()->create(['email' => buyer()->email]);
    $cold = Inquiry::factory()->create(['email' => 'cold@example.com']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListInquiries::class)
        ->set('activeTab', 'converted')
        ->assertCanSeeTableRecords([$converted])
        ->assertCanNotSeeTableRecords([$cold]);
});

// Handling one ----------------------------------------------------------------------------------

it('claims an unowned inquiry in one click', function (): void {
    $support = userWithRole(UserRole::Support);
    $inquiry = Inquiry::factory()->untouched()->create();

    $this->actingAs($support);

    Livewire\Livewire::test(ListInquiries::class)->callTableAction('assignToMe', $inquiry);

    $inquiry->refresh();

    expect($inquiry->assigned_to)->toBe($support->getKey())
        // Claiming it takes it out of the queue it was just claimed from.
        ->and($inquiry->status)->toBe(InquiryStatus::Working);
});

it('stamps a reply and appends what was said', function (): void {
    $support = userWithRole(UserRole::Support);
    $inquiry = Inquiry::factory()->untouched()->create(['note' => 'Earlier note.']);

    $this->actingAs($support);

    Livewire\Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
        ->callAction('logContact', ['note' => 'Sent them the group rate.']);

    $inquiry->refresh();

    expect($inquiry->contacted_at)->not->toBeNull()
        ->and($inquiry->status)->toBe(InquiryStatus::Answered)
        ->and($inquiry->note)->toContain('Sent them the group rate.')
        // The previous exchange is not lost to the new one.
        ->and($inquiry->note)->toContain('Earlier note.');
});

it('answers an inquiry from the list without opening it', function (): void {
    $support = userWithRole(UserRole::Support);
    $inquiry = Inquiry::factory()->untouched()->create();

    $this->actingAs($support);

    Livewire\Livewire::test(ListInquiries::class)->callTableAction('logContact', $inquiry);

    $inquiry->refresh();

    expect($inquiry->status)->toBe(InquiryStatus::Answered)
        ->and($inquiry->contacted_at)->not->toBeNull()
        // Answering something nobody claimed makes it yours.
        ->and($inquiry->assigned_to)->toBe($support->getKey());
});

it('needs a reason to close an inquiry', function (): void {
    $inquiry = Inquiry::factory()->untouched()->create();

    $this->actingAs(userWithRole(UserRole::Support));

    Livewire\Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
        ->callAction('close', ['note' => ''])
        ->assertHasActionErrors(['note' => ['required']]);

    expect($inquiry->fresh()?->status)->toBe(InquiryStatus::New);
});

it('closes an inquiry with the reason on the record', function (): void {
    $inquiry = Inquiry::factory()->untouched()->create();

    $this->actingAs(userWithRole(UserRole::Support));

    Livewire\Livewire::test(ViewInquiry::class, ['record' => $inquiry->getKey()])
        ->callAction('close', ['note' => 'No budget this year']);

    $inquiry->refresh();

    expect($inquiry->status)->toBe(InquiryStatus::Closed)
        ->and($inquiry->note)->toContain('No budget this year');
});

it('hands a selection to one person', function (): void {
    $support = userWithRole(UserRole::Support);
    $inquiries = Inquiry::factory()->count(3)->untouched()->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(ListInquiries::class)
        ->callTableBulkAction('assign', $inquiries, ['assigned_to' => $support->getKey()]);

    expect(Inquiry::query()->where('assigned_to', $support->getKey())->count())->toBe(3);
});

it('records who worked an inquiry in the audit trail', function (): void {
    $support = userWithRole(UserRole::Support);
    $inquiry = Inquiry::factory()->untouched()->create();

    $this->actingAs($support);

    Livewire\Livewire::test(ListInquiries::class)->callTableAction('assignToMe', $inquiry);

    expect($inquiry->activities()->exists())->toBeTrue();
});

// Export ------------------------------------------------------------------------------------------

/** Whatever query it is handed is what comes out — tab and filters included. */
function csvOf(Illuminate\Database\Eloquent\Builder $query): string
{
    $handle = fopen('php://memory', 'r+b');

    InquiryCsv::writeTo($handle, $query);

    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

it('exports what the screen is showing, not everything', function (): void {
    Inquiry::factory()->untouched()->create(['name' => 'Waiting Wanda']);
    Inquiry::factory()->assignedTo(userWithRole(UserRole::Support))->create(['name' => 'Handled Harry']);

    $csv = csvOf(Inquiry::query()->untouched());

    expect($csv)->toContain('Waiting Wanda')
        ->not->toContain('Handled Harry');
});

it('puts the derived outcome in the export', function (): void {
    Inquiry::factory()->create(['email' => buyer()->email, 'name' => 'Bought Betty']);
    Inquiry::factory()->create(['email' => 'cold@example.com', 'name' => 'Cold Colin']);

    $csv = csvOf(Inquiry::query());

    // Read off the orders table at export time, not from a column somebody
    // has to remember to set.
    expect($csv)->toContain('Bought')
        ->and($csv)->toContain('Not yet');
});

it('exports every column it promises', function (): void {
    Inquiry::factory()->create();

    $lines = explode("\n", csvOf(Inquiry::query()));

    expect(str_getcsv($lines[0]))->toBe(InquiryCsv::headers())
        ->and(str_getcsv($lines[1]))->toHaveCount(count(InquiryCsv::headers()));
});

// Seed data is a deliverable (§5.9) -------------------------------------------------------------------

it('seeds a register with every tab populated', function (): void {
    $this->seed(Database\Seeders\InquirySeeder::class);

    expect(Inquiry::query()->untouched()->count())->toBeGreaterThan(0)
        ->and(Inquiry::query()->where('status', InquiryStatus::Answered)->count())->toBeGreaterThan(0)
        ->and(Inquiry::query()->where('email', 'returning.enquirer@example.com')->count())->toBe(2);
});
