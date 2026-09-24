<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Pages;

use App\Enums\InquiryStatus;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Models\Inquiry;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    public function getTitle(): string
    {
        /** @var Inquiry $record */
        $record = $this->getRecord();

        return $record->name;
    }

    public function getSubheading(): ?string
    {
        /** @var Inquiry $record */
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
            ->visible(fn (Inquiry $record): bool => $record->assigned_to === null
                && $record->isOpen()
                && $this->canWork())
            ->action(function (Inquiry $record): void {
                $record->forceFill([
                    'assigned_to' => auth()->id(),
                    'status' => InquiryStatus::Working,
                ])->save();

                Notification::make()->success()->title('Yours now')->send();

                $this->refreshFormData([]);
            });
    }

    /**
     * Record a reply, and what was said.
     *
     * The note is appended rather than replacing what is there. Overwriting
     * the previous exchange is how the second message loses the first — and
     * the History panel below keeps every version anyway, but nobody reading
     * the inquiry should have to go digging for the last thing that happened.
     */
    private function logContactAction(): Action
    {
        return Action::make('logContact')
            ->label('Log a reply')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('success')
            ->visible(fn (): bool => $this->canWork())
            ->schema([
                Textarea::make('note')
                    ->label('What you told them')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Added above the existing notes, with today\'s date.'),
            ])
            ->action(function (Inquiry $record, array $data): void {
                $entry = trim((string) ($data['note'] ?? ''));

                $record->forceFill([
                    'contacted_at' => now(),
                    'status' => InquiryStatus::Answered,
                    'assigned_to' => $record->assigned_to ?? auth()->id(),
                    'note' => $entry === ''
                        ? $record->note
                        : trim(now()->format('j M Y').' — '.$entry."\n\n".(string) $record->note),
                ])->save();

                Notification::make()->success()->title('Reply logged')->send();

                $this->refreshFormData([]);
            });
    }

    /**
     * Close it without replying — spam, a duplicate, a wrong number.
     *
     * Deliberately asks for a reason. A closed inquiry with nothing behind it
     * is a row somebody re-opens in six months because nobody can remember
     * why nothing was sent.
     */
    private function closeAction(): Action
    {
        return Action::make('close')
            ->label('Close it')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (Inquiry $record): bool => $record->isOpen() && $this->canWork())
            ->schema([
                Textarea::make('note')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->maxLength(500)
                    ->placeholder('Duplicate of an earlier message; spam'),
            ])
            ->action(function (Inquiry $record, array $data): void {
                $record->forceFill([
                    'status' => InquiryStatus::Closed,
                    'note' => trim(now()->format('j M Y').' — Closed: '.$data['note']."\n\n".(string) $record->note),
                ])->save();

                Notification::make()->success()->title('Closed')->send();

                $this->refreshFormData([]);
            });
    }

    private function canWork(): bool
    {
        return auth()->user()?->can('update_inquiry') ?? false;
    }
}
