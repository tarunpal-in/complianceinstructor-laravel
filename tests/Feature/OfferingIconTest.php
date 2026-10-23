<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Models\Offering;
use App\Models\Webinar;

/* Purchase-panel icon: a single-format offering wears its format's own icon;
   a multi-format bundle wears one generic monitor. */

it('gives each format its own icon', function (): void {
    expect(Format::Live->iconName())->toBe('video')
        ->and(Format::Recorded->iconName())->toBe('circle-play')
        ->and(Format::Transcript->iconName())->toBe('file-text')
        ->and(Format::Download->iconName())->toBe('download');
});

it('wears the format icon for a single-format offering', function (): void {
    $offering = Offering::factory()->for(Webinar::factory()->create(), 'offerable')->create();
    $offering->formats()->create(['format' => Format::Transcript]);

    expect($offering->fresh()->iconName())->toBe('file-text');
});

it('wears a single monitor for a multi-format bundle', function (): void {
    $offering = Offering::factory()->for(Webinar::factory()->create(), 'offerable')->create();
    $offering->formats()->createMany([
        ['format' => Format::Live],
        ['format' => Format::Recorded],
        ['format' => Format::Transcript],
    ]);

    expect($offering->fresh()->iconName())->toBe('monitor');
});
