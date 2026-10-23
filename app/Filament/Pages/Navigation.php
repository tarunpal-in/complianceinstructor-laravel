<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\NavigationMenu;
use App\Models\Industry;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Support\Navigation as NavigationTree;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * The navigation manager — CLAUDE.md §5.6 / §6.
 *
 * A visual builder for the header and footer menus: each is a reorderable list
 * of top-level items, and each item carries its own reorderable list of
 * sub-items (a header dropdown, or the links under a footer column). The front
 * end reads these through App\Support\Navigation; before the client touches a
 * menu it falls back to the designed defaults, so nothing disappears in the gap.
 *
 * Rows are replaced wholesale on save rather than matched up — a menu item has
 * no stable identity once the client has dragged, cloned and deleted entries,
 * and the table holds a handful of rows, so a clean rewrite is simpler and
 * cannot update the wrong item's copy (the same reasoning as the page builder).
 */
class Navigation extends FilamentPage implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.navigation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|null|UnitEnum $navigationGroup = 'Content';

    protected static ?string $title = 'Navigation';

    protected static ?int $navigationSort = 30;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('update_navigation') ?? false;
    }

    public function getSubheading(): ?string
    {
        return 'The header and footer menus. Drag to reorder, nest items for dropdowns and footer columns, and leave a parent without a link to make it a heading.';
    }

    public function mount(): void
    {
        $this->getForm('form')?->fill([
            'header' => $this->toFormTree(NavigationTree::header()),
            'footer' => $this->toFormTree(NavigationTree::footer()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            // One column, so the two menu sections stack full-width.
            ->columns(1)
            ->components([
                Section::make('Header menu')
                    ->description('The top navigation. An item with sub-items becomes a dropdown.')
                    ->schema([$this->menuRepeater('header', 'Menu item', 'Dropdown items')]),

                Section::make('Footer menu')
                    ->description('The footer columns. Each top item is a column heading; its sub-items are the links beneath it.')
                    ->schema([$this->menuRepeater('footer', 'Column / link', 'Links in this column')]),
            ]);
    }

    private function menuRepeater(string $name, string $itemLabel, string $childLabel): Repeater
    {
        return Repeater::make($name)
            ->hiddenLabel()
            ->addActionLabel('Add '.strtolower($itemLabel))
            ->reorderable()
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->schema([
                ...$this->itemFields(),
                Repeater::make('children')
                    ->label($childLabel)
                    ->addActionLabel('Add item')
                    ->reorderable()
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->schema($this->itemFields())
                    ->default([]),
            ]);
    }

    /** @return array<int, \Filament\Forms\Components\Field> */
    private function itemFields(): array
    {
        return [
            TextInput::make('label')
                ->required()
                ->maxLength(80)
                ->columnSpan(2),

            Select::make('destination')
                ->label('Quick fill link')
                ->options(self::destinations())
                ->searchable()
                ->dehydrated(false)
                ->live()
                ->afterStateUpdated(fn (?string $state, Set $set) => filled($state) ? $set('url', $state) : null)
                ->helperText('Optional — picks a common page and fills the link below.'),

            TextInput::make('url')
                ->label('Link (URL)')
                ->maxLength(255)
                ->placeholder('/webinars or https://…')
                ->helperText('Leave empty for a dropdown trigger or a footer column heading.'),

            Toggle::make('open_in_new_tab')
                ->label('Open in a new tab')
                ->default(false),
        ];
    }

    public function save(): void
    {
        $state = $this->getForm('form')?->getState() ?? [];

        DB::transaction(function () use ($state): void {
            // Wholesale replace: children cascade on the parent delete.
            NavigationItem::query()->delete();

            $this->writeMenu(NavigationMenu::Header, $state['header'] ?? []);
            $this->writeMenu(NavigationMenu::Footer, $state['footer'] ?? []);
        });

        Notification::make()->title('Menus saved')->success()->send();
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function writeMenu(NavigationMenu $menu, array $items): void
    {
        $sort = 0;

        foreach ($items as $item) {
            if (blank($item['label'] ?? null)) {
                continue;
            }

            $parent = NavigationItem::create([
                'menu' => $menu,
                'parent_id' => null,
                'label' => (string) $item['label'],
                'url' => $this->cleanUrl($item['url'] ?? null),
                'sort_order' => $sort++,
                'open_in_new_tab' => (bool) ($item['open_in_new_tab'] ?? false),
            ]);

            $childSort = 0;

            foreach (($item['children'] ?? []) as $child) {
                if (blank($child['label'] ?? null)) {
                    continue;
                }

                NavigationItem::create([
                    'menu' => $menu,
                    'parent_id' => $parent->getKey(),
                    'label' => (string) $child['label'],
                    'url' => $this->cleanUrl($child['url'] ?? null),
                    'sort_order' => $childSort++,
                    'open_in_new_tab' => (bool) ($child['open_in_new_tab'] ?? false),
                ]);
            }
        }
    }

    private function cleanUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        return $url === '' ? null : $url;
    }

    /**
     * Map the normalised front-end tree into the repeater's state shape.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return array<int, array<string, mixed>>
     */
    private function toFormTree(array $tree): array
    {
        return array_map(fn (array $node): array => [
            'label' => $node['label'],
            'url' => $node['url'],
            'open_in_new_tab' => $node['new_tab'],
            'children' => array_map(fn (array $child): array => [
                'label' => $child['label'],
                'url' => $child['url'],
                'open_in_new_tab' => $child['new_tab'],
            ], $node['children']),
        ], $tree);
    }

    /**
     * Common internal destinations for the quick-fill picker, plus published
     * CMS pages — the links most menu items point at.
     *
     * @return array<string, string>
     */
    private static function destinations(): array
    {
        $core = [
            '/' => 'Home',
            '/webinars' => 'Webinars (catalogue)',
            '/speakers' => 'Speakers',
            '/membership' => 'Memberships',
            '/blog' => 'Blog',
            '/cart' => 'Cart',
            '/login' => 'Sign in',
        ];

        $pages = Page::query()
            ->where('status', 'published')
            ->where('slug', '!=', Page::HOME_SLUG)
            ->orderBy('title')
            ->pluck('title', 'slug')
            ->mapWithKeys(fn (string $title, string $slug): array => ['/'.$slug => $title.' (page)'])
            ->all();

        // The industry landing pages (route industries.show → /industry/{slug}),
        // so a menu item can point straight at one.
        $industries = Industry::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->pluck('name', 'slug')
            ->mapWithKeys(fn (string $name, string $slug): array => ['/industry/'.$slug => $name.' (industry)'])
            ->all();

        return $core + $pages + $industries;
    }
}
