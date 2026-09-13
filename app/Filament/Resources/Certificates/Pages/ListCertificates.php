<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certificates\Pages;

use App\Actions\Certificates\IssueCertificate;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\User;
use App\Models\Webinar;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->issueAction()];
    }

    /**
     * Valid first, because that is what a register is for.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'valid' => Tab::make('Valid')
                ->modifyQueryUsing(self::onlyValid(...))
                ->badge(fn (): int => Certificate::query()->valid()->count()),

            'revoked' => Tab::make('Revoked')
                ->modifyQueryUsing(self::onlyRevoked(...))
                ->badge(fn (): int => Certificate::query()->revoked()->count())
                ->badgeColor('danger'),

            'all' => Tab::make('All'),
        ];
    }

    /**
     * Issue one by hand.
     *
     * §13's descope ladder is explicit that this is how it works at launch —
     * "admin issues manually at launch; automate in month two" — because the
     * completion rules that would drive it need attendance (P5) and quiz
     * scores (P7) that do not exist yet.
     *
     * No credits field, deliberately. §12 has credit rules prescribed by the
     * accrediting bodies and not negotiable, so the number comes from the
     * webinar's own accreditation and a certificate never asserts a figure
     * somebody typed into a form.
     */
    private function issueAction(): Action
    {
        return Action::make('issue')
            ->label('Issue a certificate')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('issue_certificate') ?? false)
            ->modalHeading('Issue a CEU certificate')
            ->modalDescription('The credit hours come from the webinar\'s accreditation, not from this form — they are set by the awarding body.')
            ->modalSubmitActionLabel('Issue')
            ->schema([
                Select::make('user_id')
                    ->label('Learner')
                    ->options(fn (): array => User::query()
                        ->whereDoesntHave('roles')
                        ->orderBy('name')
                        ->limit(200)
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Select::make('webinar_id')
                    ->label('Webinar')
                    // Only accredited webinars: anything else has no credits to
                    // award and the action would refuse it anyway.
                    ->options(fn (): array => Webinar::query()
                        ->whereHas('accreditations')
                        ->orderBy('title')
                        ->limit(200)
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable()
                    ->live()
                    ->required()
                    ->helperText('Only webinars carrying at least one accreditation are listed.'),

                Select::make('accreditation_id')
                    ->label('Accrediting body')
                    // Narrowed to the bodies this webinar actually carries, so
                    // the impossible combination cannot be chosen at all.
                    ->options(fn (callable $get): array => self::bodiesFor($get('webinar_id')))
                    ->required()
                    ->helperText('Only the bodies this webinar is accredited by.'),
            ])
            ->action(function (array $data): void {
                $learner = User::query()->find($data['user_id']);
                $webinar = Webinar::query()->find($data['webinar_id']);
                $accreditation = Accreditation::query()->find($data['accreditation_id']);

                if ($learner === null || $webinar === null || $accreditation === null) {
                    Notification::make()->danger()->title('Nothing issued')->body('One of those records no longer exists.')->send();

                    return;
                }

                try {
                    $certificate = app(IssueCertificate::class)($learner, $webinar, $accreditation);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Not issued')->body($exception->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Certificate issued')
                    ->body($certificate->serial.' — '.$certificate->describeCredits().' to '.$certificate->learner_name.'.')
                    ->persistent()
                    ->send();
            });
    }

    /**
     * The accrediting bodies one webinar carries.
     *
     * @return array<int, string>
     */
    private static function bodiesFor(mixed $webinarId): array
    {
        if (blank($webinarId)) {
            return [];
        }

        return Webinar::query()
            ->find($webinarId)
            ?->accreditations()
            ->orderBy('code')
            ->pluck('code', 'accreditations.id')
            ->all() ?? [];
    }

    /**
     * Named rather than inline: a tab is handed an untyped builder, where a
     * model scope is invisible to static analysis.
     *
     * @param  Builder<Certificate>  $query
     */
    private static function onlyValid(Builder $query): void
    {
        $query->valid();
    }

    /** @param  Builder<Certificate>  $query */
    private static function onlyRevoked(Builder $query): void
    {
        $query->revoked();
    }
}
