<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\Accreditation;
use App\Models\Setting;
use App\Models\Webinar;
use App\Settings\SettingGroup;
use App\Settings\SettingRegistry;
use App\Support\Business;
use App\Support\CompletionRules;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// Untrusted input reaching a page -----------------------------------------------------

/*
 * §9's standing rule is that admin-authored values are still untrusted for
 * anything interpolated into markup. A social URL goes straight into an href,
 * which is the one field on this screen that can execute.
 */
it('refuses a javascript url on a social link', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.business_linkedin_url', 'javascript:alert(document.cookie)')
        ->call('save')
        ->assertHasErrors('data.business_linkedin_url');

    expect(Setting::get('business.linkedin_url'))->toBeNull();
});

it('refuses a data url on a social link', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.business_youtube_url', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==')
        ->call('save')
        ->assertHasErrors('data.business_youtube_url');
});

/*
 * Belt and braces: even a row written straight to the database — an import, a
 * migration, somebody with SQL access — must not produce an executable link.
 * The form is one door; the reader is the other.
 */
it('drops a dangerous social url even when it reaches the database', function (): void {
    Setting::set('business.linkedin_url', 'javascript:alert(1)', 'string');

    expect(Business::socials())->not->toHaveKey('linkedin');
});

it('keeps a normal social url', function (): void {
    Setting::set('business.linkedin_url', 'https://www.linkedin.com/company/example', 'string');

    expect(Business::socials())->toHaveKey('linkedin')
        ->and(Business::socials()['linkedin'])->toBe('https://www.linkedin.com/company/example');
});

it('escapes a business name containing markup', function (): void {
    Setting::set('business.name', '<script>alert(1)</script>Acme', 'string');
    Setting::set('promo_bar.enabled', false, 'boolean');

    $this->get('/')
        ->assertSuccessful()
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

/*
 * The promo bar's colours are interpolated into a style attribute, where a
 * stray semicolon would let somebody break out of the declaration. Setting::color
 * exists for this; the test is here because it is the sort of guard that gets
 * refactored away by somebody who cannot see why it is there.
 */
it('refuses a colour that is not a colour', function (): void {
    Setting::set('promo_bar.background_color', '#000; background-image: url(//evil.test/x)', 'color');

    expect(Setting::color('promo_bar.background_color', '#0c4d80'))->toBe('#0c4d80');
});

it('bounds a font size rather than trusting it', function (): void {
    Setting::set('promo_bar.font_size', 9999, 'integer');

    expect(Setting::pixels('promo_bar.font_size', 14))->toBeLessThanOrEqual(32);
});

// Permission bypass ---------------------------------------------------------------------

/*
 * The check that matters is in save(), not in how the form was rendered. A
 * payload can be posted to a Livewire component without the form that produced
 * it ever being drawn.
 */
it('will not let a role write a group it does not own', function (string $role, string $field, string $key, mixed $attempt): void {
    Setting::set($key, 'untouched', 'string');

    $this->actingAs(userWithRole(UserRole::from($role)));

    Livewire\Livewire::test(SettingsPage::class)
        ->set("data.{$field}", $attempt)
        ->call('save');

    expect(Setting::get($key))->toBe('untouched');
})->with([
    // Content Editor owns copy, not the business identity or money.
    [UserRole::ContentEditor->value, 'business_name', 'business.name', 'Taken Over Ltd'],
    // Finance owns money, not marketing copy.
    [UserRole::Finance->value, 'webinars_qa_note', 'webinars.qa_note', 'Rewritten'],
]);

it('keeps the settings screen shut to a role with no tab at all', function (): void {
    $this->actingAs(userWithRole(UserRole::Support))
        ->get('/admin/settings')
        ->assertForbidden();
});

it('agrees about who owns what between the tab and the save', function (): void {
    foreach ([UserRole::ContentEditor, UserRole::Finance, UserRole::Support] as $role) {
        $user = userWithRole($role);

        foreach (SettingGroup::cases() as $group) {
            // isEditableBy is the single source both the form and save() read.
            // If they ever disagree, one of them is decorative.
            expect($group->isEditableBy($user))->toBe($user->can($group->permission()));
        }
    }
});

// Zero is a value, not an absence ----------------------------------------------------------

/*
 * The classic bug in a fallback chain. `?? ` is correct and `?:` is not, and
 * the difference only shows when somebody sets a rule to zero — a body that
 * certifies immediately after a session, or one that allows nothing to be
 * skipped.
 */
it('honours a body that allows nothing to be skipped', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 10, 'integer');

    $strict = Accreditation::factory()->create(['watch_tolerance_minutes' => 0]);

    expect($strict->watchToleranceMinutes())->toBe(0)
        ->and($strict->overridesWatchTolerance())->toBeTrue();
});

it('honours a body that certifies the moment a session ends', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');

    $immediate = Accreditation::factory()->create(['live_delay_hours' => 0]);

    expect($immediate->liveDelayHours())->toBe(0);
});

it('takes a zero override into account when deciding how early to sweep', function (): void {
    Setting::set(CompletionRules::LIVE_DELAY_KEY, 24, 'integer');

    Accreditation::factory()->create(['live_delay_hours' => 0]);

    expect(Accreditation::shortestLiveDelayHours())->toBe(0);
});

it('sends a refund immediately when the delay is set to zero', function (): void {
    Setting::set('orders.refund_delay_minutes', 0, 'integer');

    expect(App\Actions\Payments\RequestRefund::delayMinutes())->toBe(0);
});

// Rules that cannot be made absurd -------------------------------------------------------------

/*
 * The floor CompletionRules keeps. A fifteen-minute session against a
 * sixty-minute tolerance would otherwise certify somebody who pressed play and
 * walked away.
 */
it('will not let a generous tolerance certify somebody who pressed play', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, 60, 'integer');

    $short = Webinar::factory()->create(['duration_minutes' => 15]);

    expect(CompletionRules::watchedSecondsRequired($short))->toBe(60)
        ->and(CompletionRules::hasWatchedEnough($short, 30))->toBeFalse()
        ->and(CompletionRules::hasWatchedEnough($short, 60))->toBeTrue();
});

it('keeps the floor for a body with its own generous tolerance', function (): void {
    $short = Webinar::factory()->create(['duration_minutes' => 15]);
    $lenient = Accreditation::factory()->create(['watch_tolerance_minutes' => 60]);

    expect(CompletionRules::watchedSecondsRequired($short, $lenient))->toBe(60);
});

it('refuses a negative setting rather than inverting the rule', function (): void {
    Setting::set(CompletionRules::WATCH_TOLERANCE_KEY, -30, 'integer');

    // A negative tolerance would otherwise demand more than the session runs.
    expect(CompletionRules::watchToleranceMinutes())->toBe(0);
});

// Reading -----------------------------------------------------------------------------------------

/*
 * Setting::declared() is now the recommended reader, and a typo in a key must
 * not read as "nothing is set" — that is the failure the registry exists to
 * make impossible, and it would be reintroduced by the very helper meant to
 * prevent it.
 */
it('refuses to read a setting that was never declared', function (): void {
    expect(fn () => Setting::declared('busines.name'))
        ->toThrow(RuntimeException::class, 'not declared in SettingRegistry');
});

it('is visible immediately after it is changed', function (): void {
    Setting::set('business.name', 'Before', 'string');
    expect(Business::name())->toBe('Before');

    Setting::set('business.name', 'After', 'string');
    expect(Business::name())->toBe('After');
});

it('falls back to the application name rather than rendering blank', function (): void {
    expect(Business::name())->toBe(config('app.name'))
        ->and(Business::legalName())->toBe(config('app.name'));
});

it('treats whitespace as absent', function (): void {
    Setting::set('business.phone', '   ', 'string');
    Setting::set('business.address', "  \n \n  ", 'string');

    expect(Business::phone())->toBeNull()
        ->and(Business::addressLines())->toBe([])
        ->and(Business::hasContactDetails())->toBeFalse();
});

it('splits an address into lines however it was typed', function (): void {
    Setting::set('business.address', "Suite 400\r\n120 Market Street\n\nBoston, MA 02109", 'string');

    expect(Business::addressLines())->toBe(['Suite 400', '120 Market Street', 'Boston, MA 02109']);
});

// Registry integrity -------------------------------------------------------------------------------

it('declares no key twice', function (): void {
    expect(count(SettingRegistry::keys()))->toBe(count(array_unique(SettingRegistry::keys())));
});

it('gives every group at least one setting, so no tab is empty', function (): void {
    foreach (SettingGroup::cases() as $group) {
        expect(SettingRegistry::forGroup($group))->not->toBeEmpty();
    }
});

/** Every media setting the delete guard protects has to be one somebody can set. */
it('declares every setting the media delete guard knows about', function (): void {
    foreach (array_keys(Setting::MEDIA_KEYS) as $key) {
        expect(SettingRegistry::has($key))->toBeTrue();
    }
});

it('keys every setting by group and name', function (): void {
    foreach (SettingRegistry::keys() as $key) {
        expect($key)->toMatch('/^[a-z_]+\.[a-z_.]+$/');
    }
});

// Saving ---------------------------------------------------------------------------------------------

it('leaves untouched groups alone when one is saved', function (): void {
    Setting::set('webinars.qa_note', 'Original note', 'string');

    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.business_name', 'Acme')
        ->call('save');

    expect(Setting::get('webinars.qa_note'))->toBe('Original note');
});

it('is unchanged by saving twice', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $page = Livewire\Livewire::test(SettingsPage::class)
        ->set('data.certificates_live_delay_hours', 30)
        ->call('save');

    $first = Setting::get(CompletionRules::LIVE_DELAY_KEY);

    $page->call('save');

    expect(Setting::get(CompletionRules::LIVE_DELAY_KEY))->toBe($first);
});

it('stores a number as a number, not as the string the form handed over', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.certificates_watch_tolerance_minutes', '7')
        ->call('save');

    expect(Setting::get(CompletionRules::WATCH_TOLERANCE_KEY))->toBe(7)->toBeInt();
});

it('stores a toggle as a boolean', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.mailing_list_double_opt_in', true)
        ->call('save');

    expect(Setting::get('mailing_list.double_opt_in'))->toBeTrue()->toBeBool();
});
