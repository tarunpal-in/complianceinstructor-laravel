<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\Settings as SettingsPage;
use App\Models\Setting;
use App\Settings\SettingGroup;
use App\Settings\SettingRegistry;
use App\Support\CompletionRules;
use App\Support\Period;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

// The guard ------------------------------------------------------------------------

/*
 * The whole reason this registry exists. Eleven settings had reached live
 * configuration with nowhere to change them — including the window an agreed
 * refund waits before the money leaves — because each was introduced as a
 * `Setting::get()` with a sensible default and the admin screen was a separate
 * job that never happened.
 */
it('refuses a setting that no screen exposes', function (): void {
    expect(fn () => Setting::get('something.nobody.declared'))
        ->toThrow(RuntimeException::class, 'not declared in SettingRegistry');
});

it('names the key it is complaining about', function (): void {
    expect(fn () => Setting::get('invented.key'))
        ->toThrow(RuntimeException::class, 'invented.key');
});

/*
 * A missing registration is a development mistake. Turning it into a 500 for a
 * customer would be the wrong trade — the declared default is a perfectly good
 * answer, and the guard has already had every chance to fire in development.
 */
it('never throws in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Setting::get('not.registered.at.all', 'fallback'))->toBe('fallback');

    app()->detectEnvironment(fn (): string => 'testing');
});

// Coverage ---------------------------------------------------------------------------

/**
 * Every key that appears as a literal `Setting::get('...')` anywhere in the
 * codebase must be declared.
 *
 * The runtime guard catches whatever the suite executes, including keys built
 * from constants and enum methods. This catches the rest — a literal in a code
 * path no test happens to reach.
 */
it('declares every setting key written as a literal in the codebase', function (): void {
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            "/Setting::(?:get|set)\(\s*'([a-z_]+\.[a-z_.]+)'/",
            (string) file_get_contents($file->getPathname()),
            $matches,
        );

        foreach ($matches[1] as $key) {
            $found[$key] = $file->getFilename();
        }
    }

    // The model's own docblock example, not a real setting.
    unset($found['group.key']);

    $undeclared = array_keys(array_diff_key($found, SettingRegistry::all()));

    expect($undeclared)->toBe([]);
});

/**
 * The mirror of the guard above: a setting nothing reads.
 *
 * Tier 1 stopped code reading a setting nobody could change. Nothing stopped
 * the opposite — a field on the screen that does nothing — and nine were
 * created in the same commit that fixed the first problem. It is the worse of
 * the two failures: a client changes the value, sees no effect, and concludes
 * the software is broken rather than that the field was never wired up.
 *
 * Exceptions are listed by name with a reason, the same shape as
 * UniqueNameReleaseTest::namesHeldOnPurpose(). The list is the point — debt
 * that is named stays visible, and removing an entry is what reminds somebody
 * to wire it up.
 */
it('is read somewhere for every setting it declares', function (): void {
    $unread = [];

    foreach (SettingRegistry::keys() as $key) {
        if (array_key_exists($key, declaredAheadOfTheirReaders())) {
            continue;
        }

        if (! settingIsReadSomewhere($key)) {
            $unread[] = $key;
        }
    }

    expect($unread)->toBe([]);
});

it('has no stale entries in the list of settings waiting for a reader', function (): void {
    // An exception that has since been wired up should be deleted, or the list
    // stops meaning anything.
    $stillUnread = array_filter(
        array_keys(declaredAheadOfTheirReaders()),
        fn (string $key): bool => ! settingIsReadSomewhere($key),
    );

    expect(array_values($stillUnread))->toBe(array_keys(declaredAheadOfTheirReaders()));
});

/**
 * Settings that exist before the thing that will read them.
 *
 * @return array<string, string>
 */
function declaredAheadOfTheirReaders(): array
{
    return [
        // Built from Format::descriptionSettingKey(), so the literal string
        // never appears in the source. Offering::describeFormats reads them.
        'formats.live.description' => 'Key is built at runtime from the Format enum.',
        'formats.recorded.description' => 'Key is built at runtime from the Format enum.',
        'formats.transcript.description' => 'Key is built at runtime from the Format enum.',
        'formats.download.description' => 'Key is built at runtime from the Format enum.',

        // Built from EmailDefinition::settingKey() ('email.enabled.'.$key), so
        // the literal never appears; EmailDefinition::isEnabled() reads it. The
        // first built, switchable email — further ones join this list as built.
        'email.enabled.marketing.webinar_shared' => 'Key is built at runtime from the email catalogue; EmailDefinition::isEnabled reads it.',
    ];
}

/** Whether the key appears anywhere outside the registry that declares it. */
function settingIsReadSomewhere(string $key): bool
{
    foreach ([base_path('app'), base_path('resources/views')] as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            if ($file->getFilename() === 'SettingRegistry.php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), "'{$key}'")) {
                return true;
            }
        }
    }

    return false;
}

/** The eleven that were live and unreachable, named so they cannot go missing again. */
it('exposes the settings that used to have no screen', function (string $key): void {
    expect(SettingRegistry::has($key))->toBeTrue();
})->with([
    'orders.refund_delay_minutes',
    'webinars.default_instructions',
    'webinars.qa_note',
    'formats.live.description',
    'formats.recorded.description',
    'formats.transcript.description',
    'formats.download.description',
    CompletionRules::LIVE_DELAY_KEY,
    CompletionRules::WATCH_TOLERANCE_KEY,
    'reports.timezone',
    'mailing_list.double_opt_in',
]);

it('gives every setting a group, a type and a field name', function (): void {
    foreach (SettingRegistry::all() as $key => $setting) {
        expect($setting->key)->toBe($key)
            ->and($setting->type)->not->toBeEmpty()
            ->and($setting->label)->not->toBeEmpty()
            // Dots would nest the value inside Filament's state path.
            ->and($setting->field())->not->toContain('.');
    }
});

it('puts every group in the ordered list', function (): void {
    expect(SettingGroup::ordered())->toHaveCount(count(SettingGroup::cases()));
});

/*
 * A select with no options is a field that renders empty and saves null, which
 * reads back as the default and looks like the save failed. And a default that
 * is not one of the options means the field opens on nothing.
 */
it('gives every select its options, with the default among them', function (): void {
    foreach (SettingRegistry::all() as $setting) {
        if ($setting->input !== 'select') {
            continue;
        }

        // toContain is variadic, so no custom message — a failure names the key
        // through the loop variable in the stack trace instead.
        expect($setting->options)->not->toBeEmpty()
            ->and(array_keys($setting->options ?? []))->toContain($setting->default);
    }
});

// Defaults ------------------------------------------------------------------------------

it('falls back to the declared default when nothing is stored', function (): void {
    expect(SettingRegistry::find('reports.timezone')?->value())->toBe('America/New_York')
        ->and(SettingRegistry::find(CompletionRules::LIVE_DELAY_KEY)?->value())
        ->toBe(CompletionRules::DEFAULT_LIVE_DELAY_HOURS);
});

it('returns what was stored once it has been set', function (): void {
    SettingRegistry::find('reports.timezone')?->store('Europe/London');

    expect(Period::timezone()->getName())->toBe('Europe/London');
});

// Permissions ---------------------------------------------------------------------------

/*
 * The split that matters. Marketing copy and the refund cooling-off window sat
 * on one screen behind one permission, so granting somebody the first granted
 * them the second.
 */
it('lets the content editor change copy but not money', function (): void {
    $editor = userWithRole(UserRole::ContentEditor);

    expect(SettingGroup::Catalogue->isEditableBy($editor))->toBeTrue()
        ->and(SettingGroup::Brand->isEditableBy($editor))->toBeTrue()
        ->and(SettingGroup::Commerce->isEditableBy($editor))->toBeFalse()
        ->and(SettingGroup::Certificates->isEditableBy($editor))->toBeFalse()
        ->and(SettingGroup::Business->isEditableBy($editor))->toBeFalse();
});

it('lets finance change money but not copy', function (): void {
    $finance = userWithRole(UserRole::Finance);

    expect(SettingGroup::Commerce->isEditableBy($finance))->toBeTrue()
        ->and(SettingGroup::Catalogue->isEditableBy($finance))->toBeFalse()
        ->and(SettingGroup::Certificates->isEditableBy($finance))->toBeFalse();
});

it('lets the super admin change everything', function (): void {
    $admin = userWithRole(UserRole::SuperAdmin);

    foreach (SettingGroup::cases() as $group) {
        expect($group->isEditableBy($admin))->toBeTrue();
    }
});

it('lets nobody change anything when signed out', function (): void {
    foreach (SettingGroup::cases() as $group) {
        expect($group->isEditableBy(null))->toBeFalse();
    }
});

// The screen -------------------------------------------------------------------------------

/*
 * A fresh install has no `settings` rows at all, so every field starts at
 * whatever default it is given. The promo bar's message is required, and its
 * copy lived in the Blade with no default behind the form — so the very first
 * save on a new site failed validation on a tab the person may never have
 * opened, and nothing else on the screen could be saved either.
 *
 * Named separately because the two tests that first caught this are about
 * permissions. Whoever breaks it next should read the failure and know what
 * broke.
 */
it('can be saved on a fresh install with nothing stored', function (): void {
    expect(Setting::query()->count())->toBe(0);

    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->call('save')
        ->assertHasNoErrors();
});

/** Every required field therefore needs a default that satisfies it. */
it('gives the promo bar message a default it can actually be saved with', function (): void {
    expect(SettingRegistry::find('promo_bar.text')?->value())->not->toBeEmpty();
});

it('saves a group the person owns', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.certificates_live_delay_hours', 48)
        ->set('data.business_name', 'Compliance Instructor LLC')
        ->call('save')
        ->assertHasNoErrors();

    expect(CompletionRules::liveDelayHours())->toBe(48)
        ->and(Setting::get('business.name'))->toBe('Compliance Instructor LLC');
});

/*
 * The permission is checked in save(), not only by rendering the field
 * disabled. A form can be rendered once and posted twice.
 */
it('refuses to save a group the person does not own', function (): void {
    Setting::set('certificates.live_delay_hours', 24, 'integer');

    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.certificates_live_delay_hours', 1)
        ->call('save');

    expect(CompletionRules::liveDelayHours())->toBe(24);
});

it('lets a content editor save the copy it owns', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.webinars_qa_note', 'Ask anything during the session.')
        ->call('save');

    expect(Setting::get('webinars.qa_note'))->toBe('Ask anything during the session.');
});

/** An empty field means "use the default", not "store an empty string". */
it('clears a setting back to its default rather than storing a blank', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    Livewire\Livewire::test(SettingsPage::class)
        ->set('data.business_legal_name', '')
        ->call('save');

    expect(Setting::get('business.legal_name'))->toBeNull();
});

it('shows every group as a tab', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/settings')
        ->assertSuccessful()
        ->assertSee('Certificates')
        ->assertSee('Catalogue')
        ->assertSee('Email &amp; list', escape: false);
});

/*
 * Disabled rather than hidden: somebody who cannot change the refund window
 * should still be able to see what it is, rather than being left unable to tell
 * whether the value is absent or whether they simply may not see it.
 */
it('shows a group it will not let you change', function (): void {
    $this->actingAs(userWithRole(UserRole::ContentEditor))
        ->get('/admin/settings')
        ->assertSuccessful()
        ->assertSee('You do not have permission to change these.');
});
