<?php

declare(strict_types=1);

use App\Actions\Leads\RecordLead;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Support\LeadCsv;
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

it('opens the register to roles that work leads', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/leads')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Support, UserRole::Finance, UserRole::ContentEditor]);

/*
 * The split that matters: Support rings these people, Finance and the content
 * editor only look. A register everybody can rewrite is one where nobody can
 * tell what was agreed with whom.
 */
it('lets only Support and the Super Admin work a lead', function (): void {
    $lead = Lead::factory()->create();

    expect(userWithRole(UserRole::Support)->can('update_lead'))->toBeTrue()
        ->and(userWithRole(UserRole::SuperAdmin)->can('update_lead'))->toBeTrue()
        ->and(userWithRole(UserRole::Finance)->can('update_lead'))->toBeFalse()
        ->and(userWithRole(UserRole::ContentEditor)->can('update_lead'))->toBeFalse()
        ->and($lead->exists)->toBeTrue();
});

/** Reading a lead and taking the whole list off the system are different things. */
it('keeps the export to roles holding export_data', function (): void {
    Lead::factory()->count(2)->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Finance))
        ->test(ListLeads::class)
        ->assertActionVisible('export');

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListLeads::class)
        ->assertActionHidden('export');
});

// Intake ------------------------------------------------------------------------------

it('records a lead through the action', function (): void {
    $lead = app(RecordLead::class)(
        name: '  Dana Whitfield ',
        email: '  DANA@Example.COM ',
        source: LeadSource::Quiz,
        company: 'Northwind',
        sourceDetail: 'Which HR gaps are costing you?',
        marketingOptIn: true,
    );

    expect($lead->email)->toBe('dana@example.com')
        ->and($lead->name)->toBe('Dana Whitfield')
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->marketing_opt_in)->toBeTrue();
});

/** §14 #2, in a smaller corner: a form does not get to say it is qualified. */
it('always starts a lead at new', function (): void {
    $lead = app(RecordLead::class)(
        name: 'Dana',
        email: 'dana@example.com',
        source: LeadSource::Contact,
    );

    expect($lead->status)->toBe(LeadStatus::New);
});

/** An imported list did not agree to anything, whatever the spreadsheet said. */
it('refuses to record consent for a source nobody submitted', function (): void {
    $lead = app(RecordLead::class)(
        name: 'Dana',
        email: 'dana@example.com',
        source: LeadSource::Import,
        marketingOptIn: true,
    );

    expect($lead->marketing_opt_in)->toBeFalse();
});

it('refuses a lead with no name or email', function (): void {
    expect(fn () => app(RecordLead::class)('  ', 'dana@example.com', LeadSource::Manual))
        ->toThrow(RuntimeException::class);
});

it('carries the campaign that produced them', function (): void {
    $request = Request::create('/quiz?utm_source=google&utm_medium=cpc&utm_campaign=hr-2026');

    $lead = app(RecordLead::class)(
        name: 'Dana',
        email: 'dana@example.com',
        source: LeadSource::Quiz,
        request: $request,
    );

    expect($lead->utm_source)->toBe('google')
        ->and($lead->utm_medium)->toBe('cpc')
        ->and($lead->utm_campaign)->toBe('hr-2026');
});

it('lowercases an email typed in by staff', function (): void {
    $lead = Lead::factory()->create(['email' => 'Mixed.Case@Example.COM']);

    expect($lead->fresh()?->email)->toBe('mixed.case@example.com');
});

it('adds a lead from the admin form', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(CreateLead::class)
        ->fillForm([
            'name' => 'Marcus Reed',
            'email' => 'marcus@example.com',
            'source' => LeadSource::Manual->value,
            'company' => 'Cedar & Vale',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Lead::query()->where('email', 'marcus@example.com')->exists())->toBeTrue();
});

// The outcome is derived, never stored -------------------------------------------------

it('says a lead bought when their email belongs to a paying account', function (): void {
    $lead = Lead::factory()->create(['email' => buyer()->email]);

    expect($lead->hasBought())->toBeTrue()
        ->and($lead->describeOutcome())->toBe('Bought');
});

it('separates registering from buying', function (): void {
    $registered = User::factory()->create(['email' => 'registered@example.com']);
    $lead = Lead::factory()->create(['email' => $registered->email]);

    expect($lead->hasAccount())->toBeTrue()
        ->and($lead->hasBought())->toBeFalse()
        ->and($lead->describeOutcome())->toBe('Registered');
});

it('says nothing came of a lead with no account', function (): void {
    $lead = Lead::factory()->create(['email' => 'stranger@example.com']);

    expect($lead->describeOutcome())->toBe('Not yet');
});

/*
 * The whole argument for deriving it: nothing on the lead row changed, and yet
 * the answer did. A `converted` column would still be saying "no".
 */
it('notices a conversion that happened after the lead was captured', function (): void {
    $lead = Lead::factory()->create(['email' => 'later@example.com']);

    expect($lead->describeOutcome())->toBe('Not yet');

    buyer('later@example.com');

    expect($lead->fresh()?->describeOutcome())->toBe('Bought');
});

it('finds converted leads without reading a status column', function (): void {
    $converted = Lead::factory()->create(['email' => buyer()->email]);
    $cold = Lead::factory()->create(['email' => 'cold@example.com']);

    $found = Lead::query()->converted()->pluck('id');

    expect($found)->toContain($converted->id)
        ->not->toContain($cold->id);
});

/** A pending order is an intent, not a purchase. */
it('does not count an unpaid order as a conversion', function (): void {
    $user = User::factory()->create(['email' => 'pending@example.com']);
    Order::factory()->for($user)->pending()->create();

    expect(Lead::factory()->create(['email' => $user->email])->hasBought())->toBeFalse();
});

// Repeats -------------------------------------------------------------------------------

it('keeps both enquiries when the same address comes back', function (): void {
    Lead::factory()->create(['email' => 'priya@example.com']);
    Lead::factory()->create(['email' => 'priya@example.com']);

    expect(Lead::query()->where('email', 'priya@example.com')->count())->toBe(2);
});

it('counts every enquiry from one address', function (): void {
    $first = Lead::factory()->create(['email' => 'priya@example.com']);
    Lead::factory()->create(['email' => 'priya@example.com']);

    expect($first->enquiries()->count())->toBe(2);
});

// The queue ---------------------------------------------------------------------------------

it('opens on the leads nobody picked up', function (): void {
    $untouched = Lead::factory()->untouched()->create();
    $worked = Lead::factory()->assignedTo(userWithRole(UserRole::Support))->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListLeads::class)
        ->assertCanSeeTableRecords([$untouched])
        ->assertCanNotSeeTableRecords([$worked])
        ->set('activeTab', 'open')
        ->assertCanSeeTableRecords([$untouched, $worked]);
});

it('counts only unpicked leads in the sidebar badge', function (): void {
    Lead::factory()->count(3)->untouched()->create();
    Lead::factory()->assignedTo(userWithRole(UserRole::Support))->create();

    expect(App\Filament\Resources\Leads\LeadResource::getNavigationBadge())->toBe('3');
});

it('shows no badge when everything has been picked up', function (): void {
    Lead::factory()->assignedTo(userWithRole(UserRole::Support))->create();

    expect(App\Filament\Resources\Leads\LeadResource::getNavigationBadge())->toBeNull();
});

it('lists converted leads on their own tab', function (): void {
    $converted = Lead::factory()->create(['email' => buyer()->email]);
    $cold = Lead::factory()->create(['email' => 'cold@example.com']);

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListLeads::class)
        ->set('activeTab', 'converted')
        ->assertCanSeeTableRecords([$converted])
        ->assertCanNotSeeTableRecords([$cold]);
});

// Working one ----------------------------------------------------------------------------------

it('claims an unowned lead in one click', function (): void {
    $support = userWithRole(UserRole::Support);
    $lead = Lead::factory()->untouched()->create();

    $this->actingAs($support);

    Livewire\Livewire::test(ListLeads::class)->callTableAction('assignToMe', $lead);

    $lead->refresh();

    expect($lead->assigned_to)->toBe($support->getKey())
        // Claiming it takes it out of the queue it was just claimed from.
        ->and($lead->status)->toBe(LeadStatus::Working);
});

it('stamps a call and appends what was said', function (): void {
    $support = userWithRole(UserRole::Support);
    $lead = Lead::factory()->untouched()->create(['note' => 'Earlier note.']);

    $this->actingAs($support);

    Livewire\Livewire::test(ViewLead::class, ['record' => $lead->getKey()])
        ->callAction('logContact', ['note' => 'Left a voicemail.']);

    $lead->refresh();

    expect($lead->contacted_at)->not->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::Working)
        ->and($lead->note)->toContain('Left a voicemail.')
        // The previous call is not lost to the new one.
        ->and($lead->note)->toContain('Earlier note.');
});

it('needs a reason to close a lead', function (): void {
    $lead = Lead::factory()->untouched()->create();

    $this->actingAs(userWithRole(UserRole::Support));

    Livewire\Livewire::test(ViewLead::class, ['record' => $lead->getKey()])
        ->callAction('close', ['note' => ''])
        ->assertHasActionErrors(['note' => ['required']]);

    expect($lead->fresh()?->status)->toBe(LeadStatus::New);
});

it('closes a lead with the reason on the record', function (): void {
    $lead = Lead::factory()->untouched()->create();

    $this->actingAs(userWithRole(UserRole::Support));

    Livewire\Livewire::test(ViewLead::class, ['record' => $lead->getKey()])
        ->callAction('close', ['note' => 'No budget this year']);

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::Unqualified)
        ->and($lead->note)->toContain('No budget this year');
});

it('hands a selection to one person', function (): void {
    $support = userWithRole(UserRole::Support);
    $leads = Lead::factory()->count(3)->untouched()->create();

    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(ListLeads::class)
        ->callTableBulkAction('assign', $leads, ['assigned_to' => $support->getKey()]);

    expect(Lead::query()->where('assigned_to', $support->getKey())->count())->toBe(3);
});

it('records who worked a lead in the audit trail', function (): void {
    $support = userWithRole(UserRole::Support);
    $lead = Lead::factory()->untouched()->create();

    $this->actingAs($support);

    Livewire\Livewire::test(ListLeads::class)->callTableAction('assignToMe', $lead);

    expect($lead->activities()->exists())->toBeTrue();
});

// Export ------------------------------------------------------------------------------------------

/** Whatever query it is handed is what comes out — tab and filters included. */
function csvOf(Illuminate\Database\Eloquent\Builder $query): string
{
    $handle = fopen('php://memory', 'r+b');

    LeadCsv::writeTo($handle, $query);

    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);

    return $csv;
}

it('exports what the screen is showing, not everything', function (): void {
    Lead::factory()->untouched()->create(['name' => 'Waiting Wanda']);
    Lead::factory()->assignedTo(userWithRole(UserRole::Support))->create(['name' => 'Handled Harry']);

    $csv = csvOf(Lead::query()->untouched());

    expect($csv)->toContain('Waiting Wanda')
        ->not->toContain('Handled Harry');
});

it('puts the derived outcome in the export', function (): void {
    Lead::factory()->create(['email' => buyer()->email, 'name' => 'Bought Betty']);
    Lead::factory()->create(['email' => 'cold@example.com', 'name' => 'Cold Colin']);

    $csv = csvOf(Lead::query());

    // Read off the orders table at export time, not from a column somebody
    // has to remember to set.
    expect($csv)->toContain('Bought')
        ->and($csv)->toContain('Not yet');
});

it('exports every column it promises', function (): void {
    Lead::factory()->create();

    $lines = explode("\n", csvOf(Lead::query()));

    expect(str_getcsv($lines[0]))->toBe(LeadCsv::headers())
        ->and(str_getcsv($lines[1]))->toHaveCount(count(LeadCsv::headers()));
});

// Seed data is a deliverable (§5.9) -------------------------------------------------------------------

it('seeds a register with every tab populated', function (): void {
    $this->seed(Database\Seeders\LeadSeeder::class);

    expect(Lead::query()->untouched()->count())->toBeGreaterThan(0)
        ->and(Lead::query()->where('status', LeadStatus::Qualified)->count())->toBeGreaterThan(0)
        ->and(Lead::query()->where('email', 'returning.enquirer@example.com')->count())->toBe(2);
});
