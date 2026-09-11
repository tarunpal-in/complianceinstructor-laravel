<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans\Pages;

use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Resources\MembershipPlans\Schemas\MembershipPlanForm;
use App\Models\MembershipPlan;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMembershipPlan extends EditRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = MembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                /*
                 * A plan with members on it must not disappear. Their allowances
                 * survive — they are snapshotted on the subscription — but the
                 * plan is what every screen names the thing they are paying for,
                 * and a soft-deleted one reads as "no plan" everywhere.
                 *
                 * Taking it off the pricing page is what was actually meant, and
                 * the notification says so rather than just refusing.
                 */
                ->before(function (DeleteAction $action): void {
                    /** @var MembershipPlan $plan */
                    $plan = $this->getRecord();

                    $reason = $plan->deletionBlockReason();

                    if ($reason === null) {
                        return;
                    }

                    Notification::make()
                        ->danger()
                        ->title('There are still members on this plan')
                        ->body($reason)
                        ->persistent()
                        ->send();

                    $action->cancel();
                }),

            // The way back. A deleted plan is reachable through the list's
            // "Deleted plans" filter, and this is what undoes it.
            RestoreAction::make(),
        ];
    }

    /**
     * The same mapping as create, so a plan edited cannot end up shaped
     * differently from one created.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->resolveSlug(MembershipPlanForm::mutate($data));
    }

    /**
     * Sent alongside the "cannot be sold yet" warning where both apply — a plan
     * that was renamed *and* has no Stripe price has two things wrong with it,
     * and neither message should displace the other.
     */
    protected function afterSave(): void
    {
        $this->notifyIfSlugChanged();
    }

    /**
     * Say plainly when a saved plan cannot be sold.
     *
     * Filament's own "Saved" notification is true and useless here: a plan with
     * no Stripe price saves perfectly and then fails at the moment a customer
     * tries to pay, which is the worst possible time to find out.
     */
    protected function getSavedNotification(): ?Notification
    {
        /** @var MembershipPlan $plan */
        $plan = $this->getRecord();

        $plan->load('entitlements');

        $problems = $plan->problems();

        if ($problems === []) {
            return Notification::make()->success()->title('Saved');
        }

        return Notification::make()
            ->warning()
            ->title('Saved, but this plan cannot be sold yet')
            ->body(implode(' ', $problems))
            ->persistent();
    }
}
