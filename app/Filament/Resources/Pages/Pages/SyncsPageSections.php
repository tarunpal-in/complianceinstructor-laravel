<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use App\Models\PageSection;
use Illuminate\Support\Facades\DB;

/**
 * Moves blocks between the Builder field and `page_sections`.
 *
 * Filament's Builder keeps its state as one array, which would naturally be a
 * JSON column. §5.6 asks for rows, so something has to translate — and both the
 * create and the edit page need the same translation, which is what this is.
 *
 * Rows are replaced wholesale rather than matched up and updated. Blocks have
 * no stable identity across a save: the client drags them, clones them and
 * deletes them, and there is nothing in the Builder's state to match an
 * existing row against. Trying to be clever here would mean sometimes updating
 * the wrong block's copy, which is worse than a few extra inserts on a table
 * that holds a handful of rows per page.
 */
trait SyncsPageSections
{
    /** @var array<int, array<string, mixed>> */
    private array $pendingSections = [];

    /**
     * Take the blocks out of the form payload and hold them.
     *
     * `sections` is not a column — leaving it in would have Eloquent reject the
     * save under Model::shouldBeStrict().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function liftSections(array $data): array
    {
        $this->pendingSections = PageForm::toSections(
            is_array($data['sections'] ?? null) ? $data['sections'] : [],
        );

        unset($data['sections']);

        return $data;
    }

    /** Write the held blocks as this page's sections. */
    protected function writeSections(Page $page): void
    {
        DB::transaction(function () use ($page): void {
            $page->sections()->delete();

            foreach ($this->pendingSections as $section) {
                PageSection::create([...$section, 'page_id' => $page->getKey()]);
            }
        });

        $page->load('sections');
    }

    /**
     * Rows back into the Builder's shape when the form opens.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillSections(array $data, Page $page): array
    {
        $data['sections'] = PageForm::fromSections($page->sections);

        return $data;
    }
}
