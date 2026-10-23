<?php

declare(strict_types=1);

use App\Filament\Resources\Pages\Schemas\PageBlocks;

/*
 * Converting one page block into a compatible one without losing content (§2 /
 * §5.6). The builder keeps items as { type, data }, so a conversion is just a
 * type swap with the data kept — shared fields carry over, non-shared ones lie
 * dormant, so a round trip restores everything.
 */

it('turns a Text block into Text-with-image, keeping heading and body', function (): void {
    $items = [
        'abc' => [
            'type' => 'rich_text',
            'data' => ['heading' => 'Our mission', 'body' => '<p>Staying current.</p>', 'is_visible' => true],
        ],
    ];

    $result = PageBlocks::convertItem($items, 'abc', 'media_text');

    expect($result['abc']['type'])->toBe('media_text')
        ->and($result['abc']['data'])->toBe([
            'heading' => 'Our mission',
            'body' => '<p>Staying current.</p>',
            'is_visible' => true,
        ]);
});

it('round-trips without losing the image, going back to Text and forward again', function (): void {
    $items = [
        'xyz' => [
            'type' => 'media_text',
            'data' => [
                'heading' => 'Who we serve',
                'body' => '<p>Compliance teams.</p>',
                'image' => 'pages/serve.jpg',
                'image_position' => 'left',
                'image_alt' => 'A team at work',
                'is_visible' => true,
            ],
        ],
    ];

    // media_text -> rich_text: image fields stay in data, just unused.
    $toText = PageBlocks::convertItem($items, 'xyz', 'rich_text');
    expect($toText['xyz']['type'])->toBe('rich_text')
        ->and($toText['xyz']['data']['image'])->toBe('pages/serve.jpg');

    // rich_text -> media_text: the image comes back intact.
    $backToMedia = PageBlocks::convertItem($toText, 'xyz', 'media_text');
    expect($backToMedia['xyz']['type'])->toBe('media_text')
        ->and($backToMedia['xyz']['data']['image'])->toBe('pages/serve.jpg')
        ->and($backToMedia['xyz']['data']['image_position'])->toBe('left')
        ->and($backToMedia['xyz']['data']['heading'])->toBe('Who we serve');
});

it('leaves the state untouched for an unknown item key', function (): void {
    $items = ['abc' => ['type' => 'rich_text', 'data' => ['body' => '<p>Hi.</p>']]];

    expect(PageBlocks::convertItem($items, 'nope', 'media_text'))->toBe($items);
});

it('offers a convert action for each compatible direction', function (): void {
    $names = array_map(fn ($action): string => $action->getName(), PageBlocks::convertActions());

    expect($names)->toContain('convert_rich_text_to_media_text')
        ->toContain('convert_media_text_to_rich_text');
});
