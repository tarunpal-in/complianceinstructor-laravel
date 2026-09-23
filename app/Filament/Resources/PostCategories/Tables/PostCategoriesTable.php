<?php

declare(strict_types=1);

namespace App\Filament\Resources\PostCategories\Tables;

use App\Models\PostCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PostCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (PostCategory $record): ?string => $record->description)
                    ->wrap()
                    ->width('26rem'),

                TextColumn::make('posts_count')
                    ->label('Articles')
                    // No ->counts(): the resource query already adds the alias,
                    // and adding it twice puts the same alias in the select
                    // twice, which MySQL rejects outright.
                    ->alignEnd()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Available')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                TrashedFilter::make()->label('Deleted categories'),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    /*
                     * Deleting a category leaves its articles uncategorised —
                     * `post_category_id` is nullOnDelete, because losing the
                     * articles would be far worse. Said plainly rather than
                     * discovered by finding a dozen posts nothing links to.
                     */
                    ->before(function (DeleteAction $action, PostCategory $record): void {
                        $count = $record->posts()->count();

                        if ($count === 0) {
                            return;
                        }

                        Notification::make()
                            ->warning()
                            ->title('That category has articles in it')
                            ->body($count.' '.str('article')->plural($count).' would be left uncategorised, and nothing on the site links to an uncategorised article. Move them first, or switch the category off instead.')
                            ->persistent()
                            ->send();

                        $action->cancel();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No categories yet')
            ->emptyStateDescription('An article needs one before it can be published.');
    }
}
