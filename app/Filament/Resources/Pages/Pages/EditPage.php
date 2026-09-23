<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Pages;

use App\Enums\PageStatus;
use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPage extends EditRecord
{
    use ResolvesSlugOnSave, SyncsPageSections;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->viewAction(),
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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Page $page */
        $page = $this->getRecord();

        return $this->fillSections($data, $page);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->resolveSlug($this->liftSections($data));
    }

    protected function afterSave(): void
    {
        /** @var Page $page */
        $page = $this->getRecord();

        $this->writeSections($page);
        $this->notifyIfSlugChanged();
    }

    // Publishing ------------------------------------------------------------------

    /** Straight to the page itself, which is the only real preview. */
    private function viewAction(): Action
    {
        return Action::make('visit')
            ->label('View page')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->url(fn (Page $record): string => $record->path())
            ->openUrlInNewTab()
            ->visible(fn (Page $record): bool => $record->isLive());
    }

    /**
     * Publishing is a gated action, not a status dropdown.
     *
     * The same shape as publishing a webinar, and for the same reason: a page
     * with nothing visible on it publishes as a blank screen with the site's
     * navigation around it, and it is found by a customer rather than by the
     * client. Refusing with the reasons is more useful than saving something
     * that looks fine in a list.
     */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->visible(fn (Page $record): bool => ! $record->isLive()
                && (auth()->user()?->can('update_page') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Publish this page?')
            ->modalDescription(fn (Page $record): string => 'It becomes reachable at '.$record->path().'.')
            ->action(function (Page $record): void {
                $record->load('sections');

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
                    'status' => PageStatus::Published,
                    // Kept if it was already set — a page taken down and put
                    // back should not claim to be new.
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

    /** Take it down without deleting it. */
    private function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label('Take down')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->visible(fn (Page $record): bool => $record->isLive()
                && (auth()->user()?->can('update_page') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Take this page down?')
            ->modalDescription('It stops being reachable. The content is kept, and the date it first went live is kept with it.')
            ->action(function (Page $record): void {
                $record->update(['status' => PageStatus::Draft]);

                Notification::make()->warning()->title('Taken down')->send();

                $this->refreshFormData(['status']);
            });
    }
}
