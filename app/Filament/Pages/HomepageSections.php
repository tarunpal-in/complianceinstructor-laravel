<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Schemas\HomeBlocks;
use App\Filament\Resources\Pages\Pages\SyncsPageSections;
use App\Models\Page;
use App\Support\HomepageDefaults;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * The homepage editor — CLAUDE.md §2 / §5.6.
 *
 * The homepage is one `Page` (slug `home`) whose sections are blocks. Rather
 * than expose it in the ordinary Pages list, where "publish" and "address" make
 * no sense for the front door, it gets its own screen: a curated set of the
 * designed homepage sections that the client can edit, reorder and hide — but
 * not add arbitrary blocks to, which is what keeps the layout intact.
 *
 * Storage is the same `page_sections` rows and the same translation the page
 * builder uses (SyncsPageSections + PageForm), so nothing new has to be
 * remembered about how a block is saved.
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
        return 'The sections of the public homepage. Drag to reorder, and use the toggle inside a section to hide it without losing the copy.';
    }

    public function mount(): void
    {
        $page = Page::home();

        // The editor locks adding and removing blocks, so an empty home page
        // could not be rebuilt from this screen. Backfill the designed sections
        // if someone has cleared them (or on a page that was never seeded).
        if ($page->sections()->doesntExist()) {
            HomepageDefaults::apply($page);
        }

        $this->getForm('form')?->fill($this->fillSections([], $page->load('sections')));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Sections')
                    ->schema([
                        Builder::make('sections')
                            ->hiddenLabel()
                            ->blocks(HomeBlocks::all())
                            // Curated: the client edits, reorders and hides the
                            // designed sections, but cannot add, duplicate or
                            // delete them. That is what protects the layout.
                            ->addable(false)
                            ->deletable(false)
                            ->cloneable(false)
                            ->reorderable()
                            ->collapsible()
                            ->blockNumbers(false),
                    ]),
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

        $page = Page::home();

        $this->liftSections($data);
        $this->writeSections($page);

        Notification::make()->title('Homepage saved')->success()->send();
    }
}
