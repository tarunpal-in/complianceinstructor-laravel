<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\PublishStatus;
use App\Filament\Forms\Components\SeoFields;
use App\Models\Page;
use App\Models\PageSection;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The page builder — CLAUDE.md §2.
 *
 * The Builder field is the whole leverage decision made visible: the client
 * assembles a page from blocks, and a new block type costs a case in PageBlocks
 * and a Blade partial rather than a model, a table, a controller and a form.
 *
 * Filament's Builder keeps its state as one array, which would naturally be
 * stored as a JSON column. §5.6 asks for `page_sections` rows instead, and the
 * reason holds: a section that is a row can be asked about. The translation
 * between the two lives here — `toSections()` on the way out, `fromSections()`
 * on the way in — so it is one pair of methods rather than a rule everybody has
 * to remember.
 */
class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            // One column, so each section (and the block builder in particular)
            // spans the full width instead of the sections tiling two-up — the
            // builder's block fields need the room.
            ->columns(1)
            ->components([

                Section::make()->columns(2)->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                            if ($operation === 'create') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->prefix(config('app.url').'/')
                        // No unique rule: a clash resolves to "{slug}-2" on save and
                        // is reported, rather than refusing the save.
                        ->rule(static function (): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail): void {
                                /*
                             * A page cannot take a path the application already
                             * answers on. It would be unreachable — the route
                             * that owns the path wins — and taking `admin`
                             * would make the panel look broken to whoever did
                             * it. Refused here rather than discovered later.
                             */
                                if (in_array(Str::slug((string) $value), Page::RESERVED_SLUGS, true)) {
                                    $fail('That address is already used by the site itself. Pick another.');
                                }
                            };
                        })
                        ->helperText('The page\'s address. Changing it on a published page breaks existing links to it.'),
                ]),

                Section::make('Content')
                    ->description('Blocks are drawn top to bottom. Drag to reorder, and use the toggle inside a block to hide it without losing the copy.')
                    ->schema([
                        Builder::make('sections')
                            ->hiddenLabel()
                            ->blocks(PageBlocks::all())
                            // Turn a block into a compatible one without retyping
                            // its copy — e.g. a Text block into Text with image.
                            ->extraItemActions(PageBlocks::convertActions())
                            ->addActionLabel('Add a block')
                            ->collapsible()
                            ->cloneable()
                            ->blockNumbers(false),
                    ]),

                Section::make('Publishing')
                    ->columns(3)
                    ->schema([
                        Select::make('status')
                            ->options(PublishStatus::options())
                            ->default(PublishStatus::Draft->value)
                            ->required()
                            ->live()
                            // Publishing goes through the gated action on the edit
                            // page, which checks the page is not empty first. This
                            // is here so a published page can be taken down again.
                            ->helperText('Use the Publish action rather than this — it checks the page has something on it.'),

                        DateTimePicker::make('published_at')
                            ->label('Live from')
                            ->seconds(false)
                            ->placeholder('Immediately')
                            ->helperText('A future date holds the page back until then.'),

                        Select::make('layout')
                            ->options(['standard' => 'Standard'])
                            ->default('standard')
                            ->required()
                            // One value, and honestly labelled as such. A second
                            // arrives with the first page that cannot use the
                            // standard frame.
                            ->helperText('Only one frame so far.'),
                    ]),
                SeoFields::make(),

            ]);
    }

    /**
     * The builder's state as rows for `page_sections`.
     *
     * `is_visible` is lifted out of each block's data onto the row, so "which
     * pages have a hidden block" stays a query.
     *
     * Typed as `mixed` per entry rather than as blocks, and the guards below
     * are why: this is form payload. §4 rule 3 keeps request input out of a
     * model, and claiming a shape here that has not been checked would be the
     * same trust in a different place.
     *
     * @param  array<int|string, mixed>  $state
     * @return array<int, array<string, mixed>>
     */
    public static function toSections(array $state): array
    {
        $rows = [];

        foreach (array_values($state) as $index => $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $rows[] = [
                'type' => $block['type'],
                // Order is the order they were arranged in. Stored rather than
                // implied by id, so dragging a block changes the page.
                'sort_order' => $index,
                'is_visible' => (bool) ($data['is_visible'] ?? true),
                'data' => array_diff_key($data, ['is_visible' => null]),
            ];
        }

        return $rows;
    }

    /**
     * Rows back into the shape the Builder field expects.
     *
     * @param  iterable<int, PageSection>  $sections
     * @return array<int, array<string, mixed>>
     */
    public static function fromSections(iterable $sections): array
    {
        $state = [];

        foreach ($sections as $section) {
            $state[] = [
                'type' => $section->type,
                // Visibility goes back inside the block, because that is where
                // its toggle lives on the form. toSections() lifts it out again.
                'data' => [...($section->data ?? []), 'is_visible' => $section->is_visible],
            ];
        }

        return $state;
    }
}
