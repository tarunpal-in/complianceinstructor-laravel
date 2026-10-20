<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\UserRole;
use App\Models\Offering;
use App\Models\Webinar;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->webinar = Webinar::factory()->create();
    Livewire\Livewire::actingAs(userWithRole(UserRole::SuperAdmin));
});

it('renders the options table with each row labelled from its formats', function (): void {
    // A bundle whose "Option" column name is composed from its formats — the
    // table must eager-load formats or strict mode throws a lazy-load violation.
    $bundle = Offering::factory()->everything()->for($this->webinar, 'offerable')->create(['sku' => 'BND']);
    $single = Offering::factory()->withFormats(Format::Transcript)->for($this->webinar, 'offerable')->create(['sku' => 'TRN']);

    manager($this->webinar)
        ->assertCanSeeTableRecords([$bundle, $single])
        ->assertSee($bundle->label())
        ->assertSee($single->label());
});

it('creates a bundle granting three formats', function (): void {
    manager($this->webinar)->callTableAction('create', data: [
        'format_values' => [Format::Live->value, Format::Recorded->value, Format::Transcript->value],
        'price_cents' => '279.00',
        'sku' => 'BUNDLE1',
        'seats_per_unit' => 1,
        'sort_order' => 10,
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $offering = Offering::query()->where('sku', 'BUNDLE1')->firstOrFail();

    expect($offering->formatEnums())->toHaveCount(3)
        ->and($offering->label())->toBe('Live Webinar + Recorded + Transcript (PDF)');
});

/**
 * §4 rule 6 — money is integer cents, and the conversion must not go through a
 * float. (float) '19.99' * 100 is 1998.9999999999998, which truncates to 1998.
 */
it('stores a typed price as exact cents', function (string $typed, int $expected): void {
    manager($this->webinar)->callTableAction('create', data: [
        'format_values' => [Format::Recorded->value],
        'price_cents' => $typed,
        'sku' => 'PRICE'.Str::random(4),
        'seats_per_unit' => 1,
        'sort_order' => 0,
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    expect(Offering::query()->latest('id')->first()->price_cents)->toBe($expected);
})->with([
    ['19.99', 1999],
    ['279', 27900],
    ['0.05', 5],
    ['1.5', 150],
    ['199.00', 19900],
]);

it('keeps the add-on price optional', function (): void {
    manager($this->webinar)->callTableAction('create', data: [
        'format_values' => [Format::Recorded->value],
        'price_cents' => '199.00',
        'addon_price_cents' => null,
        'sku' => 'NOADDON',
        'seats_per_unit' => 1,
        'sort_order' => 0,
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    $offering = Offering::query()->where('sku', 'NOADDON')->firstOrFail();

    expect($offering->addon_price_cents)->toBeNull()
        ->and($offering->isAvailableAsAddon())->toBeFalse()
        ->and($offering->priceCents(asAddon: true))->toBe(19900);
});

it('replaces the formats when an offering is edited', function (): void {
    $offering = Offering::factory()->everything()->for($this->webinar, 'offerable')->create();

    manager($this->webinar)->callTableAction('edit', $offering, data: [
        'format_values' => [Format::Transcript->value],
        'price_cents' => '179.00',
        'sku' => $offering->sku,
        'seats_per_unit' => 1,
        'sort_order' => 0,
        'is_active' => true,
    ])->assertHasNoTableActionErrors();

    expect($offering->refresh()->formatEnums())->toHaveCount(1)
        ->and($offering->grants(Format::Transcript))->toBeTrue()
        ->and($offering->grants(Format::Live))->toBeFalse();
});

/**
 * A recorded webinar has no session, so the live format is not even offered as
 * a choice — the publish gate would refuse it, but the form should not let it
 * be entered in the first place.
 */
it('does not offer the live format on a recorded webinar', function (): void {
    $recorded = Webinar::factory()->recorded()->create();

    manager($recorded)->callTableAction('create', data: [
        'format_values' => [Format::Live->value],
        'price_cents' => '199.00',
        'sku' => 'SHOULDFAIL',
        'seats_per_unit' => 1,
        'sort_order' => 0,
        'is_active' => true,
    ])->assertHasTableActionErrors(['format_values']);
});

it('requires at least one format', function (): void {
    manager($this->webinar)->callTableAction('create', data: [
        'format_values' => [],
        'price_cents' => '199.00',
        'sku' => 'NOFORMAT',
        'seats_per_unit' => 1,
        'sort_order' => 0,
        'is_active' => true,
    ])->assertHasTableActionErrors(['format_values']);
});
