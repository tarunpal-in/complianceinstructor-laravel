<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Webinars\WebinarResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWebinar extends CreateRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = WebinarResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->resolveSlug($data);
    }

    /**
     * Sent alongside the draft notice, not instead of it — a webinar that was
     * renamed still needs its offerings and credits adding.
     */
    protected function afterCreate(): void
    {
        $this->notifyIfSlugChanged();
    }

    /**
     * Straight to the edit page rather than back to the list.
     *
     * Purchase options, credits and deliverables are relation managers, and a
     * relation manager needs a saved parent to attach rows to — so none of them
     * can appear on this page. Redirecting to the list would leave the client
     * bounced away from a half-finished webinar with no indication that the
     * important half is still missing.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Webinar saved as a draft')
            ->body('Now add its purchase options, credits and deliverables below. It cannot be published without an option and at least one accreditation.')
            ->success()
            ->persistent();
    }
}
