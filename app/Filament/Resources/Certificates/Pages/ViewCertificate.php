<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certificates\Pages;

use App\Actions\Certificates\IssueCertificate;
use App\Actions\Certificates\RevokeCertificate;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Certificate;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class ViewCertificate extends ViewRecord
{
    protected static string $resource = CertificateResource::class;

    public function getTitle(): string
    {
        /** @var Certificate $record */
        $record = $this->getRecord();

        return $record->serial;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->revokeAction(),
            $this->reissueAction(),
        ];
    }

    /**
     * Withdraw it — §8, "Admin can revoke and reissue".
     *
     * Gated on `issue_certificate`'s sibling permission rather than on a role:
     * §8 improvement #3 makes every action gate on a named permission, and
     * awarding or withdrawing continuing-education credit is reserved to the
     * Super Admin by the seeded role map. Support can read a certificate and
     * cannot change what somebody has been certified in.
     */
    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Certificate $record): bool => $record->isValid()
                && (auth()->user()?->can('revoke_certificate') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Revoke this certificate?')
            ->modalDescription('It stays on the record and on any verification lookup, shown as withdrawn. A serial that resolved to nothing would read as a forgery.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Issued against the wrong webinar')
                    ->helperText('Shown to anyone who looks the serial up, including an accrediting body.'),
            ])
            ->action(function (Certificate $record, array $data): void {
                try {
                    app(RevokeCertificate::class)($record, (string) $data['reason']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not revoked')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Certificate revoked')->send();

                $this->refreshFormData([]);
            });
    }

    /**
     * Revoke this one and issue a fresh one in its place.
     *
     * A certificate is not corrected in place — it is what it said on the day —
     * so the way to fix a wrong name or a restated credit figure is a new
     * document with a new serial, leaving both on the record.
     *
     * The replacement is built from the live records, which is the point:
     * reissuing after correcting the webinar's credits produces a certificate
     * carrying the corrected figure.
     */
    private function reissueAction(): Action
    {
        return Action::make('reissue')
            ->label('Revoke and reissue')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            // Both permissions, because this does both things — and resolved
            // once rather than twice, which also keeps the second check from
            // reading as a possibility the first has already ruled out.
            ->visible(function (Certificate $record): bool {
                $user = auth()->user();

                return $record->isValid()
                    && $user !== null
                    && $user->can('issue_certificate')
                    && $user->can('revoke_certificate');
            })
            ->requiresConfirmation()
            ->modalHeading('Replace this certificate?')
            ->modalDescription('This one is withdrawn and a new one issued in its place, with a new serial and today\'s details. Use it after correcting a name or the webinar\'s credit hours.')
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(255)
                    ->placeholder('Learner\'s name was misspelled'),
            ])
            ->action(function (Certificate $record, array $data): void {
                $learner = $record->user;
                $webinar = $record->webinar;
                $accreditation = $record->accreditation;

                if ($learner === null || $webinar === null || $accreditation === null) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot reissue')
                        ->body('The learner, webinar or accrediting body behind this certificate no longer exists. Revoke it instead.')
                        ->send();

                    return;
                }

                try {
                    // Revoked first: IssueCertificate refuses a second live
                    // certificate for the same learner, webinar and body, and
                    // that guard is doing its job here rather than being worked
                    // around.
                    app(RevokeCertificate::class)($record, (string) $data['reason']);

                    $replacement = app(IssueCertificate::class)($learner, $webinar, $accreditation);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not reissued')->body($exception->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Reissued')
                    ->body('New serial '.$replacement->serial.'. The old one is on the record as withdrawn.')
                    ->persistent()
                    ->send();

                $this->redirect(CertificateResource::getUrl('view', ['record' => $replacement]));
            });
    }
}
