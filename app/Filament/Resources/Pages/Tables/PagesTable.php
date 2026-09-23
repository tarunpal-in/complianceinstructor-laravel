<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Tables;

use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    // The address underneath, because "which page is /about"
                    // is how the client thinks about these.
                    ->description(fn (Page $record): string => $record->path())
                    ->wrap()
                    ->width('22rem'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // Derived, not the column: a page published with a future
                    // date is scheduled, and a list saying otherwise beside the
                    // date would be arguing with itself.
                    ->state(fn (Page $record): string => $record->derivedStatus()->label())
                    ->color(fn (Page $record): string => $record->derivedStatus()->color())
                    ->description(fn (Page $record): ?string => $record->published_at?->format('j M Y')),

                TextColumn::make('content')
                    ->label('Content')
                    ->state(fn (Page $record): string => $record->describeContent())
                    ->color(fn (Page $record): string => $record->sections->isEmpty() ? 'danger' : 'gray'),

                TextColumn::make('updated_at')
                    ->label('Edited')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('title')
            ->filters([
                /*
                 * No status filter: the tabs answer that, and a filter doing a
                 * tab's job narrows every other tab on top of its own query.
                 *
                 * The trashed filter is load-bearing rather than decoration —
                 * the resource query strips the soft-delete scope so a deleted
                 * page can still be opened and restored, and this is what puts
                 * it back.
                 */
                TrashedFilter::make()->label('Deleted pages'),
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
            ->emptyStateHeading('No pages yet')
            ->emptyStateDescription('Build one from blocks — no developer required.');
    }

    /**
     * Open the page itself — the only real preview there is.
     *
     * Works whatever state the page is in. A live page opens at its address; a
     * draft opens through a signed, expiring link, because writing a page you
     * cannot look at until you publish it is backwards, and publishing to check
     * means the client sees the half-finished version.
     */
    private static function visitAction(): Action
    {
        return Action::make('visit')
            ->label(fn (Page $record): string => $record->isLive() ? 'View' : 'Preview')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Page $record): string => $record->viewableUrl())
            ->openUrlInNewTab()
            ->tooltip(fn (Page $record): string => $record->isLive()
                ? 'Open '.$record->path()
                : 'Open a private preview. The link works for '.Page::PREVIEW_LIFETIME_DAYS.' days and can be shared.');
    }
}
