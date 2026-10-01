<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLog\Pages;

use App\Email\Resend;
use App\Enums\SuppressionReason;
use App\Filament\Resources\EmailLog\EmailSendResource;
use App\Models\EmailSend;
use App\Models\EmailSuppression;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * One email, and what can be done about it.
 *
 * Three actions, all of which add rather than change: resend rebuilt, resend as
 * it was, and hold the address back. Nothing here edits or deletes the row.
 */
class ViewEmailSend extends ViewRecord
{
    protected static string $resource = EmailSendResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->resendRebuiltAction(),
            $this->resendVerbatimAction(),
            $this->suppressAction(),
        ];
    }

    /**
     * Send it again, built from current data.
     *
     * The default offer, and the only honest one for anything holding a signed
     * link: a body stored in March contains a URL that expired in March, so
     * replaying it would send a dead page and report success.
     */
    private function resendRebuiltAction(): Action
    {
        return Action::make('resendRebuilt')
            ->label('Resend')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn (EmailSend $record): bool => $this->mayResend() && $record->mayResendRebuilt())
            ->modalHeading('Send this again?')
            ->modalDescription('Rebuilt from the current template and the current data, so any link in it will be freshly signed and will work.')
            ->schema($this->recipientField())
            ->action(fn (EmailSend $record, array $data) => $this->perform(
                fn (): EmailSend => app(Resend::class)->rebuilt(
                    $record,
                    auth()->user(),
                    $this->correctedAddress($record, $data),
                ),
            ));
    }

    /**
     * Replay exactly what was stored.
     *
     * Right for a receipt: history must not change because a template was edited
     * since. Hidden rather than disabled where it is unsafe — a greyed-out button
     * invites somebody to go looking for a way to force it.
     */
    private function resendVerbatimAction(): Action
    {
        return Action::make('resendVerbatim')
            ->label('Resend exactly as it was')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (EmailSend $record): bool => $this->mayResend() && $record->mayResendVerbatim())
            ->modalHeading('Replay the stored copy?')
            ->modalDescription('Sends the exact content that went out the first time, even if the template has changed since.')
            ->schema($this->recipientField())
            ->action(fn (EmailSend $record, array $data) => $this->perform(
                fn (): EmailSend => app(Resend::class)->verbatim(
                    $record,
                    auth()->user(),
                    $this->correctedAddress($record, $data),
                ),
            ));
    }

    /**
     * The corrected-address field.
     *
     * The commonest real reason to resend is a mistyped address, and a resend to
     * the same wrong address achieves nothing. The original row keeps the
     * original address either way, so what went where stays readable.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function recipientField(): array
    {
        return [
            TextInput::make('to_email')
                ->label('Send to')
                ->email()
                ->required()
                ->default(fn (EmailSend $record): string => $record->to_email)
                ->helperText('Change this if the first attempt went to the wrong address.'),
        ];
    }

    /**
     * A new address, or null to keep the original.
     *
     * @param  array<string, mixed>  $data
     */
    private function correctedAddress(EmailSend $record, array $data): ?string
    {
        $entered = is_string($data['to_email'] ?? null) ? trim($data['to_email']) : '';

        return $entered === '' || strcasecmp($entered, $record->to_email) === 0 ? null : $entered;
    }

    /**
     * Run a resend, reporting what actually happened.
     *
     * The Dispatcher refuses rather than throws for an ordinary reason — a
     * suppressed address, a spent allowance, an email switched off — so the
     * resulting row has to be read back. Saying "sent" when the row says
     * suppressed would be the screen lying about the log on the same page as the
     * log.
     *
     * @param  callable(): EmailSend  $send
     */
    private function perform(callable $send): void
    {
        /*
         * Rate limited per record. The failure this guards against is somebody
         * watching a queue that looks stuck and clicking five times — which on a
         * consumer Gmail account spends five of a few hundred daily sends and
         * puts five copies in a customer's inbox.
         */
        $key = 'resend:'.$this->record->getKey();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            Notification::make()->warning()
                ->title('Slow down')
                ->body('This email has been resent three times in the last few minutes. Wait a moment before trying again.')
                ->send();

            return;
        }

        RateLimiter::hit($key, 300);

        try {
            $result = $send();
        } catch (Throwable $exception) {
            Notification::make()->danger()
                ->title('Not resent')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        if ($result->status->isSettled() && $result->status !== \App\Enums\EmailStatus::Sent) {
            Notification::make()->warning()
                ->title('Not sent')
                ->body($result->outcome_note ?? $result->error ?? 'See the new row in the log.')
                ->send();

            return;
        }

        Notification::make()->success()
            ->title('On its way to '.$result->to_email)
            ->body('A new row has been added to the log. The original is unchanged.')
            ->send();
    }

    /**
     * Hold this address back.
     *
     * Reached from here because this is where somebody is standing when they
     * realise an address is dead — they have a bounce message open beside the row
     * it refers to. Nothing populates suppressions automatically on a consumer
     * Gmail account, so this is the only way they get written.
     */
    private function suppressAction(): Action
    {
        return Action::make('suppress')
            ->label('Hold this address back')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (EmailSend $record): bool => ($this->mayResend())
                && ! EmailSuppression::blocks($record->to_email, isRequired: false))
            ->modalHeading('Stop sending to this address?')
            ->modalDescription('A bouncing or invalid address stops everything, including invoices — sending to a mailbox that does not exist damages deliverability for everybody else. A spam complaint stops marketing only.')
            ->schema([
                Select::make('reason')
                    ->label('Why')
                    ->options(SuppressionReason::options())
                    ->default(SuppressionReason::Bounced->value)
                    ->required(),
                Textarea::make('note')
                    ->label('Note')
                    ->rows(2)
                    ->helperText('What the bounce message said, or who asked.')
                    ->maxLength(255),
            ])
            ->action(function (EmailSend $record, array $data): void {
                EmailSuppression::record(
                    email: $record->to_email,
                    reason: SuppressionReason::from((string) $data['reason']),
                    source: 'Added from the email log',
                    note: is_string($data['note'] ?? null) ? $data['note'] : null,
                    sendId: $record->getKey(),
                    by: auth()->user(),
                );

                Notification::make()->success()
                    ->title('Held back')
                    ->body($record->to_email.' will not be emailed until somebody lifts it.')
                    ->send();
            });
    }

    private function mayResend(): bool
    {
        return auth()->user()?->can('resend_email') ?? false;
    }
}
