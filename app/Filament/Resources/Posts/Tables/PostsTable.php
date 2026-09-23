<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Tables;

use App\Models\Post;
use App\Models\PostCategory;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('cover')
                    ->collection('cover')
                    ->conversion('card')
                    ->label('')
                    ->height(44)
                    ->width(70)
                    ->extraImgAttributes(['class' => 'rounded object-cover']),

                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Post $record): string => $record->summary(90))
                    ->wrap()
                    ->width('24rem'),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->badge()
                    // Publishable state depends on this, so an empty one is
                    // worth seeing rather than being a blank cell.
                    ->placeholder('Uncategorised')
                    ->color(fn (Post $record): string => $record->category === null ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    // Derived, not the column: published with a future date is
                    // scheduled, and a list saying otherwise beside the date
                    // would be arguing with itself.
                    ->badge()
                    ->state(fn (Post $record): string => $record->derivedStatus()->label())
                    ->color(fn (Post $record): string => $record->derivedStatus()->color())
                    ->description(fn (Post $record): ?string => $record->published_at?->format('j M Y')),

                TextColumn::make('author.name')
                    ->label('Author')
                    ->placeholder('No byline')
                    ->toggleable(),

                TextColumn::make('reading')
                    ->label('Length')
                    ->state(fn (Post $record): string => $record->describeReadingTime())
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('post_category_id')
                    ->label('Category')
                    ->options(fn (): array => PostCategory::query()->orderBy('name')->pluck('name', 'id')->all()),

                /*
                 * No status filter — the tabs answer that, and a filter doing a
                 * tab's job narrows every other tab on top of its own query.
                 *
                 * The trashed filter is load-bearing: the resource query strips
                 * the soft-delete scope so a deleted article can still be opened
                 * and restored, and this is what puts the scope back.
                 */
                TrashedFilter::make()->label('Deleted articles'),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                self::visitAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing written yet')
            ->emptyStateDescription('The blog is what produces new indexable pages between webinars.');
    }

    /**
     * Read the article — live at its address, or through a signed preview.
     *
     * The same mechanism as a page's, because writing something you cannot look
     * at until you publish it is backwards whatever it is.
     */
    private static function visitAction(): Action
    {
        return Action::make('visit')
            ->label(fn (Post $record): string => $record->isLive() ? 'Read' : 'Preview')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Post $record): string => $record->viewableUrl())
            ->openUrlInNewTab()
            ->tooltip(fn (Post $record): string => $record->isLive()
                ? 'Open '.$record->path()
                : 'Open a private preview. The link works for '.Post::PREVIEW_LIFETIME_DAYS.' days and can be shared.');
    }
}
