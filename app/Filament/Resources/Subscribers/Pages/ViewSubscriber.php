<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Pages;

use App\Actions\Subscribers\ConfirmSubscription;
use App\Actions\Subscribers\Subscribe;
use App\Actions\Subscribers\Unsubscribe;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Subscriber;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSubscriber extends ViewRecord
{
    protected static string $resource = SubscriberResource::class;

    public function getTitle(): string
    {
        /** @var Subscriber $record */
        $record = $this->getRecord();

        return $record->email;
    }

    public function getSubheading(): ?string
    {
        /** @var Subscriber $record */
        $record = $this->getRecord();

        return $record->describeConsent();
    }

    /** The same three decisions as the list, for somebody who opened the record first. */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmAction(),
            $this->unsubscribeAction(),
            $this->resubscribeAction(),
        ];
    }

    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Subscriber $record): bool => $record->isAwaitingConfirmation() && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Confirm this signup?')
            ->modalDescription('Use this when somebody has told you the confirmation email never arrived. It records that you confirmed it, not that they did.')
            ->action(function (Subscriber $record): void {
                app(ConfirmSubscription::class)($record);

                Notification::make()->success()->title('Confirmed')->send();

                $this->refreshFormData([]);
            });
    }

    private function unsubscribeAction(): Action
    {
        return Action::make('unsubscribe')
            ->label('Unsubscribe')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->visible(fn (Subscriber $record): bool => ! $record->isSuppressed() && $this->canManage())
            ->modalHeading('Take this address off the list?')
            ->modalDescription('They stop receiving campaigns. The row stays, because it is what keeps them off the list if a future import includes them.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Asked by email')
                    ->helperText('Optional, and kept on the record.'),
            ])
            ->action(function (Subscriber $record, array $data): void {
                $reason = $data['reason'] ?? null;

                app(Unsubscribe::class)(
                    $record,
                    is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
                );

                Notification::make()->success()->title('Taken off the list')->send();

                $this->refreshFormData([]);
            });
    }

    private function resubscribeAction(): Action
    {
        return Action::make('resubscribe')
            ->label('Put back on')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (Subscriber $record): bool => $record->isSuppressed() && $this->canManage())
            ->requiresConfirmation()
            ->modalHeading('Put this address back on the list?')
            ->modalDescription(fn (Subscriber $record): string => $record->status === SubscriberStatus::Complained
                ? 'This address reported us as spam. Only do this if they have asked you to, in writing — mailing a complainant again is what gets a sending domain blocked.'
                : 'They start receiving campaigns again. Only do this if they have asked you to.')
            ->action(function (Subscriber $record): void {
                app(Subscribe::class)(
                    email: $record->email,
                    source: SubscriberSource::Manual,
                    name: $record->name,
                    asStaff: true,
                );

                Notification::make()->success()->title('Back on the list')->send();

                $this->refreshFormData([]);
            });
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('update_subscriber') ?? false;
    }
}
