<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Pages;

use App\Actions\Subscribers\Subscribe;
use App\Enums\SubscriberSource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Subscriber;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSubscriber extends CreateRecord
{
    protected static string $resource = SubscriberResource::class;

    protected static ?string $title = 'Add an address';

    /**
     * Through the action, not straight to the model.
     *
     * The rules about what happens to an address that is already known — and
     * in particular the one about not putting a spam complainant back on the
     * list — live in Subscribe, and a Filament page writing the row directly
     * would be a second, quieter way onto the list that does not follow them.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = $data['name'] ?? null;

        return app(Subscribe::class)(
            email: (string) $data['email'],
            source: SubscriberSource::from((string) ($data['source'] ?? SubscriberSource::Manual->value)),
            name: is_string($name) && trim($name) !== '' ? $name : null,
            request: request(),
            // Staff: this is the one route that may overrule a suppression,
            // and it is the route somebody has to be signed in to reach.
            asStaff: true,
        );
    }

    protected function getRedirectUrl(): string
    {
        /** @var Subscriber $record */
        $record = $this->getRecord();

        return SubscriberResource::getUrl('view', ['record' => $record]);
    }
}
