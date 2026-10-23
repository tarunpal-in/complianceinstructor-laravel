<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\Offering;
use App\Models\Webinar;

it('sets a flat $65 add-on on every webinar offering, singles and combos', function (): void {
    $webinar = Webinar::factory()->create();

    $single = Offering::factory()->withFormats(Format::Recorded)
        ->for($webinar, 'offerable')->create(['addon_price_cents' => null]);

    $combo = Offering::factory()->withFormats(Format::Live, Format::Recorded)
        ->for($webinar, 'offerable')->create(['addon_price_cents' => null]);

    $this->artisan('offerings:addon-price')->assertSuccessful();

    expect($single->fresh()->addon_price_cents)->toBe(6500)
        ->and($single->fresh()->isAvailableAsAddon())->toBeTrue()
        ->and($combo->fresh()->addon_price_cents)->toBe(6500)
        ->and($combo->fresh()->isAvailableAsAddon())->toBeTrue();
});

it('accepts a custom amount in cents', function (): void {
    $single = Offering::factory()->withFormats(Format::Live)
        ->for(Webinar::factory()->create(), 'offerable')->create(['addon_price_cents' => null]);

    $this->artisan('offerings:addon-price', ['cents' => 5000])->assertSuccessful();

    expect($single->fresh()->addon_price_cents)->toBe(5000);
});

it('writes nothing on a dry run', function (): void {
    $single = Offering::factory()->withFormats(Format::Live)
        ->for(Webinar::factory()->create(), 'offerable')->create(['addon_price_cents' => null]);

    $this->artisan('offerings:addon-price', ['--dry-run' => true])->assertSuccessful();

    expect($single->fresh()->addon_price_cents)->toBeNull();
});
