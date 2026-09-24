<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    public function getTitle(): string
    {
        /** @var Lead $record */
        $record = $this->getRecord();

        return $record->name;
    }

    public function getSubheading(): ?string
    {
        /** @var Lead $record */
        $record = $this->getRecord();

        return $record->describeRole() ?? $record->email;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->takeAction(),
            $this->logContactAction(),
            $this->closeAction(),
            EditAction::make(),
        ];
    }

    private function takeAction(): Action
    {
        return Action::make('take')
            ->label('Take it')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('primary')
            ->visible(fn (Lead $record): bool => $record->assigned_to === null
                && $record->isOpen()
                && $this->canWork())
            ->action(function (Lead $record): void {
                $record->forceFill([
                    'assigned_to' => auth()->id(),
                    'status' => LeadStatus::Working,
                ])->save();

                Notification::make()->success()->title('Yours now')->send();

                $this->refreshFormData([]);
            });
    }

    /**
     * Record a call, and what was said.
     *
     * The note is appended rather than replacing what is there. Overwriting the
     * previous call's note is how the second conversation loses the first — and
     * the History panel below keeps every version anyway, but nobody reading
     * the lead should have to go digging for the last thing that happened.
     */
    private function logContactAction(): Action
    {
        return Action::make('logContact')
            ->label('Log a call')
            ->icon(Heroicon::OutlinedPhone)
            ->color('gray')
            ->visible(fn (): bool => $this->canWork())
            ->schema([
                Textarea::make('note')
                    ->label('What was said')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Added above the existing notes, with today\'s date.'),
            ])
            ->action(function (Lead $record, array $data): void {
                $entry = trim((string) ($data['note'] ?? ''));

                $record->forceFill([
                    'contacted_at' => now(),
                    'status' => $record->status === LeadStatus::New ? LeadStatus::Working : $record->status,
                    'assigned_to' => $record->assigned_to ?? auth()->id(),
                    'note' => $entry === ''
                        ? $record->note
                        : trim(now()->format('j M Y').' — '.$entry."\n\n".(string) $record->note),
                ])->save();

                Notification::make()->success()->title('Call logged')->send();

                $this->refreshFormData([]);
            });
    }

    /**
     * Close it off.
     *
     * Deliberately asks for a reason. "Not a fit" with nothing behind it is a
     * row somebody re-opens in six months because nobody can remember why.
     */
    private function closeAction(): Action
    {
        return Action::make('close')
            ->label('Not a fit')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Lead $record): bool => $record->isOpen() && $this->canWork())
            ->schema([
                Textarea::make('note')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Wrong size of organisation; no budget this year'),
            ])
            ->action(function (Lead $record, array $data): void {
                $record->forceFill([
                    'status' => LeadStatus::Unqualified,
                    'note' => trim(now()->format('j M Y').' — Closed: '.$data['note']."\n\n".(string) $record->note),
                ])->save();

                Notification::make()->success()->title('Closed')->send();

                $this->refreshFormData([]);
            });
    }

    private function canWork(): bool
    {
        return auth()->user()?->can('update_lead') ?? false;
    }
}
