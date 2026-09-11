<?php

declare(strict_types=1);

namespace App\Filament\Resources\Webinars\Pages;

use App\Enums\WebinarStatus;
use App\Filament\Concerns\ResolvesSlugOnSave;
use App\Filament\Resources\Webinars\WebinarResource;
use App\Models\Offering;
use App\Models\Webinar;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class EditWebinar extends EditRecord
{
    use ResolvesSlugOnSave;

    protected static string $resource = WebinarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->publishAction(),
            $this->duplicateAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->resolveSlug($data);
    }

    protected function afterSave(): void
    {
        $this->notifyIfSlugChanged();
    }

    /**
     * Publishing is a gated action, not a status dropdown.
     *
     * A webinar with no active offering renders in the catalogue and then fails
     * at add-to-cart, because §5.3 makes `offerings` the only place a price
     * exists. Refusing with the reasons is more useful than saving a record
     * that looks fine and breaks in front of a customer.
     */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->visible(fn (Webinar $record): bool => $record->status === WebinarStatus::Draft
                && (auth()->user()?->can('update_webinar') ?? false))
            ->requiresConfirmation()
            ->modalHeading('Publish this webinar?')
            ->modalDescription('It becomes visible in the public catalogue and can be bought.')
            ->action(function (Webinar $record): void {
                $problems = $record->publishableProblems();

                if ($problems !== []) {
                    Notification::make()
                        ->title('Not ready to publish')
                        ->body('<ul class="list-disc ps-4"><li>'.implode('</li><li>', array_map('e', $problems)).'</li></ul>')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $record->update(['status' => WebinarStatus::Scheduled]);

                Notification::make()
                    ->title('Published')
                    ->body('It is now live in the catalogue.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Webinars repeat: same topic, new date, several times a year. Without this
     * the client retypes six long-form fields, every offering and every credit
     * each time — which is what decides whether they enjoy using the admin.
     *
     * The copy is a draft with no date, so it can never be mistaken for the
     * original or accidentally sold on the wrong schedule.
     */
    private function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('create_webinar') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Duplicate this webinar?')
            ->modalDescription('Content, purchase options and credits are copied. The copy is a draft with no date.')
            ->action(function (Webinar $record): void {
                $copy = $record->replicate(['slug', 'status', 'scheduled_at']);
                $copy->title = $record->title.' (copy)';
                $copy->slug = Str::slug($record->title).'-copy-'.Str::lower(Str::random(5));
                $copy->status = WebinarStatus::Draft;
                $copy->scheduled_at = null;
                $copy->save();

                // Offerings carry their formats on a pivot, so each has to be
                // rebuilt rather than replicated as a single row.
                $record->offerings()->with('formats')->get()->each(
                    function (Offering $offering) use ($copy): void {
                        $new = $offering->replicate(['sku']);
                        $new->offerable_id = $copy->id;
                        $new->sku = Str::upper(Str::random(10));
                        $new->save();

                        foreach ($offering->formatEnums() as $format) {
                            $new->formats()->create(['format' => $format]);
                        }
                    },
                );

                foreach ($record->accreditations as $accreditation) {
                    $copy->accreditations()->attach($accreditation->id, [
                        // getAttribute rather than ->credits: the pivot is a
                        // generic Pivot, so the column is not a known property.
                        'credits' => $accreditation->pivot?->getAttribute('credits') ?? 0,
                    ]);
                }

                Notification::make()
                    ->title('Duplicated')
                    ->body('Set a date and publish when it is ready.')
                    ->success()
                    ->send();

                $this->redirect(WebinarResource::getUrl('edit', ['record' => $copy]));
            });
    }
}
