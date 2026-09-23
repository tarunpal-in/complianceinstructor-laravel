<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Pages;

use App\Enums\PublishStatus;
use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPost extends EditRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->readAction(),
            $this->publishAction(),
            $this->unpublishAction(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->resolveSlug($data);
    }

    protected function afterSave(): void
    {
        $this->notifyIfSlugChanged();
    }

    // Publishing --------------------------------------------------------------------

    /** Read it as a reader would — live, or through a signed preview. */
    private function readAction(): Action
    {
        return Action::make('visit')
            ->label(fn (Post $record): string => $record->isLive() ? 'Read article' : 'Preview')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Post $record): string => $record->viewableUrl())
            ->openUrlInNewTab()
            ->tooltip(fn (Post $record): ?string => $record->isLive()
                ? null
                : 'A private link that works for '.Post::PREVIEW_LIFETIME_DAYS.' days. Safe to send to somebody for review.');
    }

    /**
     * Publishing is a gated action, not a status dropdown.
     *
     * The same shape as a page and a webinar. An article with no body is a
     * headline over nothing; one with no category is published and then linked
     * to from nowhere, which for an SEO-driven business is the same as not
     * publishing it while looking like you did.
     */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->visible(fn (Post $record): bool => ! $record->isLive()
                && (auth()->user()?->can('update_post') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Publish this article?')
            ->modalDescription(fn (Post $record): string => 'It becomes readable at '.$record->path().'.')
            ->action(function (Post $record): void {
                $problems = $record->problems();

                if ($problems !== []) {
                    Notification::make()
                        ->danger()
                        ->title('Not ready to publish')
                        ->body('<ul class="list-disc ps-4"><li>'.implode('</li><li>', array_map('e', $problems)).'</li></ul>')
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update([
                    'status' => PublishStatus::Published,
                    // Kept if already set — an article taken down and put back
                    // should not claim to be new, and its URL has been indexed
                    // under the original date.
                    'published_at' => $record->published_at ?? now(),
                ]);

                Notification::make()
                    ->success()
                    ->title('Published')
                    ->body('Live at '.$record->path().'.')
                    ->send();

                $this->refreshFormData(['status', 'published_at']);
            });
    }

    private function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label('Take down')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->visible(fn (Post $record): bool => $record->isLive()
                && (auth()->user()?->can('update_post') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Take this article down?')
            ->modalDescription('It stops being readable and leaves the blog index. Anything already linking to it will get a 404 — §5.6 has a redirects table for when that matters.')
            ->action(function (Post $record): void {
                $record->update(['status' => PublishStatus::Draft]);

                Notification::make()->warning()->title('Taken down')->send();

                $this->refreshFormData(['status']);
            });
    }
}
