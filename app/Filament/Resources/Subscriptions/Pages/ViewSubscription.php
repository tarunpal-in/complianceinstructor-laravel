<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Actions\Memberships\SnapshotPlanEntitlements;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    public function getTitle(): string
    {
        /** @var Subscription $record */
        $record = $this->getRecord();

        return $record->member->name ?? 'Deleted member';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->resumeAction(),
            $this->cancelAtPeriodEndAction(),
            $this->cancelNowAction(),
            $this->reapplyPlanTermsAction(),
        ];
    }

    // Cashier's side ----------------------------------------------------------

    /**
     * End the membership when the paid period runs out.
     *
     * Through Cashier rather than by writing `ends_at` ourselves: Stripe has to
     * agree, or it bills them again next month and the admin quietly becomes
     * the thing that is wrong. §3 gives Cashier this job for that reason.
     */
    private function cancelAtPeriodEndAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel at period end')
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->visible(fn (Subscription $record): bool => $this->mayManage() && ! $record->canceled())
            ->disabled(fn (): bool => ! self::stripeIsConfigured())
            ->tooltip(fn (): ?string => self::stripeIsConfigured() ? null : self::NO_STRIPE)
            ->requiresConfirmation()
            ->modalHeading('Cancel this membership at the end of the period?')
            ->modalDescription('They keep access until the period they have paid for runs out, and are not charged again.')
            ->action(fn (Subscription $record) => $this->throughStripe(
                fn () => $record->cancel(),
                'Cancelled. Access continues until the end of the paid period.',
            ));
    }

    /**
     * End it now.
     *
     * Separate from the above rather than a checkbox on it, because the two do
     * materially different things to somebody who has already paid and the
     * difference must not come down to a box nobody read. This one does not
     * refund — that is the Payments screen, and deliberately a second decision.
     */
    private function cancelNowAction(): Action
    {
        return Action::make('cancelNow')
            ->label('Cancel immediately')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Subscription $record): bool => $this->mayManage() && ! $record->ended())
            ->disabled(fn (): bool => ! self::stripeIsConfigured())
            ->tooltip(fn (): ?string => self::stripeIsConfigured() ? null : self::NO_STRIPE)
            ->requiresConfirmation()
            ->modalHeading('End this membership now?')
            ->modalDescription('Access stops immediately, including any period they have already paid for. No refund is issued — do that from Payments if it is owed.')
            ->action(fn (Subscription $record) => $this->throughStripe(
                fn () => $record->cancelNow(),
                'Ended. Access has stopped.',
            ));
    }

    /** Take back a cancellation while the member is still inside their period. */
    private function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Resume')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->visible(fn (Subscription $record): bool => $this->mayManage() && $record->onGracePeriod())
            ->disabled(fn (): bool => ! self::stripeIsConfigured())
            ->tooltip(fn (): ?string => self::stripeIsConfigured() ? null : self::NO_STRIPE)
            ->requiresConfirmation()
            ->modalHeading('Resume this membership?')
            ->modalDescription('Billing restarts on the original schedule. Only possible before the paid period runs out.')
            ->action(fn (Subscription $record) => $this->throughStripe(
                fn () => $record->resume(),
                'Resumed. Billing continues as before.',
            ));
    }

    // Our side ----------------------------------------------------------------

    /**
     * Put this member onto the plan's current terms.
     *
     * The deliberate exception to "an active subscriber keeps what they signed
     * up under" — agreed 2026-09-11 — and it stays an explicit, confirmed
     * action for exactly that reason. It exists because the other half of that
     * agreement is that somebody has to be able to fix a mistake: a plan
     * mis-configured at signup, or a goodwill upgrade.
     *
     * Local only. Nothing here touches Stripe, because what a plan grants is
     * ours and Stripe has never heard of it.
     */
    private function reapplyPlanTermsAction(): Action
    {
        return Action::make('reapplyPlanTerms')
            ->label('Re-apply plan terms')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->visible(fn (Subscription $record): bool => $this->mayManage()
                && $record->plan !== null
                && $record->termsDifferFromPlan())
            ->requiresConfirmation()
            ->modalHeading('Replace this member\'s allowances with the plan\'s?')
            ->modalDescription(fn (Subscription $record): string => sprintf(
                'They currently have: %s. The plan grants: %s. This overwrites what they were sold, so only do it to correct a mistake.',
                $record->describeAllowances(),
                $record->plan?->describeAllowances() ?? '—',
            ))
            ->modalSubmitActionLabel('Overwrite their allowances')
            ->action(function (Subscription $record): void {
                app(SnapshotPlanEntitlements::class)($record);

                Notification::make()
                    ->title('Allowances replaced')
                    ->body('This member now holds the plan\'s current terms.')
                    ->success()
                    ->send();

                $this->refreshFormData([]);
            });
    }

    // Plumbing ----------------------------------------------------------------

    private const NO_STRIPE = 'Stripe is not configured, so nothing here can reach it. Set STRIPE_SECRET first.';

    private function mayManage(): bool
    {
        return auth()->user()?->can('update_subscription') ?? false;
    }

    private static function stripeIsConfigured(): bool
    {
        // The same config entry the refund driver reads — through config rather
        // than env(), because config:cache would otherwise blank it.
        return filled(config('services.stripe.secret'));
    }

    /**
     * Run something against Stripe and report what came back.
     *
     * Cashier throws on a declined or impossible operation, and §14 #16 bans
     * returning an exception message as the response — so the message is put
     * in front of the administrator as a notification, and the exception is
     * left to the logger.
     */
    private function throughStripe(callable $operation, string $success): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Stripe refused that')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->title($success)->success()->send();

        $this->refreshFormData([]);
    }
}
