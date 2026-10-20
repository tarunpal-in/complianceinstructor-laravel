<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Schemas\HomeBlocks;
use App\Filament\Resources\Pages\Pages\SyncsPageSections;
use App\Models\Page;
use App\Support\HomepageDefaults;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * The homepage editor — CLAUDE.md §2 / §5.6.
 *
 * The homepage is one `Page` (slug `home`) whose sections are `page_sections`
 * rows. Rather than expose it in the ordinary Pages list, where "publish" and
 * "address" make no sense for the front door, it gets its own screen.
 *
 * The form is a tab per section (its copy, grouped into fieldsets) plus a
 * leading "Arrange" tab that drags the sections into order and hides them —
 * each section's content lives under its own key in the form state (a Group
 * with that state path), and the Arrange list carries the order and the
 * visibility. Save folds the two back into ordered rows (writeSections), so
 * "which homepage section is hidden" stays a query (§5.6) exactly as the page
 * builder stores it.
 */
class HomepageSections extends FilamentPage implements HasForms
{
    use InteractsWithForms, SyncsPageSections;

    protected string $view = 'filament.pages.homepage-sections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Homepage Sections';

    protected static ?string $title = 'Homepage Sections';

    protected static ?string $slug = 'homepage-sections';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** The same gate the page builder uses — editing the homepage is editing a page. */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('update_page') ?? false;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'A tab for each section of the public homepage. Use “Arrange” to drag them into order and hide any without losing its copy.';
    }

    public function mount(): void
    {
        $page = Page::home();

        // The editor locks adding and removing sections, so an empty home page
        // could not be rebuilt from this screen. Backfill the designed sections
        // if someone has cleared them (or on a page that was never seeded).
        if ($page->sections()->doesntExist()) {
            HomepageDefaults::apply($page);
        }

        $this->getForm('form')?->fill($this->stateFromSections($page->load('sections')));
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [
            Tab::make('Arrange')
                ->icon(Heroicon::OutlinedArrowsUpDown)
                ->schema([$this->arrangeField()]),
        ];

        foreach (HomeBlocks::definitions() as $definition) {
            $tabs[] = Tab::make($definition['label'])
                ->icon($definition['icon'])
                // Each section's fields live under their own key, so the tabs do
                // not collide on a shared field name like "heading".
                ->schema([
                    Group::make()
                        ->statePath($definition['type'])
                        ->schema($definition['schema']),
                ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Sections')
                    ->tabs($tabs)
                    ->persistTabInQueryString()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The "Arrange" list: one row per section, dragged into the public order,
     * each with a show/hide toggle. Rows are fixed (the set is curated) — only
     * their order and visibility are editable.
     */
    private function arrangeField(): Repeater
    {
        return Repeater::make('arrange')
            ->hiddenLabel()
            ->addable(false)
            ->deletable(false)
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->itemLabel(fn (array $state): string => HomeBlocks::labels()[$state['type'] ?? ''] ?? 'Section')
            ->schema([
                Hidden::make('type'),
                Toggle::make('is_visible')
                    ->label('Show this section')
                    ->default(true)
                    ->helperText('Turn off to hide it from the homepage without losing its copy.'),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('visit')
                ->label('View homepage')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url('/')
                ->openUrlInNewTab(),

            Action::make('save')
                ->label('Save changes')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->getForm('form')?->getState() ?? [];

        $this->pendingSections = $this->sectionsFromState($data);
        $this->writeSections(Page::home());

        Notification::make()->title('Homepage saved')->success()->send();
    }

    /**
     * Rows → form state: the Arrange list in stored order, and each section's
     * data under its own key.
     *
     * @return array<string, mixed>
     */
    private function stateFromSections(Page $page): array
    {
        $state = ['arrange' => []];

        foreach ($page->sections->sortBy('sort_order') as $section) {
            if (! HomeBlocks::isKnown($section->type)) {
                continue;
            }

            $state['arrange'][] = ['type' => $section->type, 'is_visible' => (bool) $section->is_visible];
            $state[$section->type] = $section->data ?? [];
        }

        // Any designed section the page is missing (e.g. added after the page was
        // seeded) joins the end, visible, with empty copy.
        foreach (HomeBlocks::knownTypes() as $type) {
            if (! array_key_exists($type, $state)) {
                $state['arrange'][] = ['type' => $type, 'is_visible' => true];
                $state[$type] = [];
            }
        }

        return $state;
    }

    /**
     * Form state → rows: order and visibility come from the Arrange list, the
     * copy from each section's own key. Unknown or malformed rows are dropped
     * rather than trusted (§4 rule 3).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function sectionsFromState(array $data): array
    {
        $arrange = is_array($data['arrange'] ?? null) ? $data['arrange'] : [];

        $rows = [];
        $order = 0;

        foreach ($arrange as $item) {
            $type = is_array($item) ? ($item['type'] ?? null) : null;

            if (! is_string($type) || ! HomeBlocks::isKnown($type)) {
                continue;
            }

            // $item is an array here: a non-array would have made $type null above
            // and been skipped by the continue.
            $rows[] = [
                'type' => $type,
                'sort_order' => $order++,
                'is_visible' => (bool) ($item['is_visible'] ?? true),
                'data' => is_array($data[$type] ?? null) ? $data[$type] : [],
            ];
        }

        return $rows;
    }
}
