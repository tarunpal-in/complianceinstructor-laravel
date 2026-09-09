<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        /** @var Order $record */
        $record = $this->getRecord();

        return $record->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->reconcileAction(),
            $this->cancelAction(),
        ];
    }

    /**
     * Put the stored status back in step with the payment rows.
     *
     * §9 makes payments the source of truth, so when the two disagree — a
     * missed webhook, a hand-edited row — the fix is to re-derive rather than to
     * let someone pick a status from a dropdown. That is exactly the affordance
     * §14 #2 exists to remove.
     */
    private function reconcileAction(): Action
    {
        return Action::make('reconcile')
            ->label('Re-derive status')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Order $record): bool => ! $record->statusMatchesPayments()
                && (auth()->user()?->can('update_order') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Re-derive this order status?')
            ->modalDescription(fn (Order $record): string => sprintf(
                'The payments say %s; the order says %s.',
                $record->derivedStatus()->label(),
                $record->status->label(),
            ))
            ->action(function (Order $record): void {
                $record->load('payments.refunds');
                $derived = $record->derivedStatus();

                $record->update(['status' => $derived]);

                Notification::make()
                    ->title('Status re-derived')
                    ->body('Now '.$derived->label().', matching the payment records.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Cancelling is only ever available before money has been taken.
     *
     * Once a payment has succeeded the way back is a refund, which leaves both
     * the capture and the reversal on the record.
     */
    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel order')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Pending
                && (auth()->user()?->can('update_order') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Cancel this order?')
            ->modalDescription('Nothing has been captured against it. It stays on the record as cancelled.')
            ->action(function (Order $record): void {
                $record->update(['status' => OrderStatus::Cancelled]);

                Notification::make()->title('Order cancelled')->success()->send();
            });
    }
}
