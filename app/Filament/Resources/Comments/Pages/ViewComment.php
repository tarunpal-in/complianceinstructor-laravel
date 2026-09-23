<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\Pages;

use App\Actions\Comments\ModerateComment;
use App\Enums\CommentStatus;
use App\Filament\Resources\Comments\CommentResource;
use App\Models\Comment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewComment extends ViewRecord
{
    protected static string $resource = CommentResource::class;

    public function getTitle(): string
    {
        /** @var Comment $record */
        $record = $this->getRecord();

        return $record->author_name;
    }

    public function getSubheading(): ?string
    {
        /** @var Comment $record */
        $record = $this->getRecord();

        return 'On '.($record->post->title ?? 'a deleted article');
    }

    /**
     * The same three decisions as the list, for somebody who opened a comment
     * to read it in full before making one.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->decisionAction('approve', CommentStatus::Approved, 'Publish', 'success', Heroicon::OutlinedCheckCircle),
            $this->decisionAction('spam', CommentStatus::Spam, 'Spam', 'danger', Heroicon::OutlinedNoSymbol),
            $this->decisionAction('reject', CommentStatus::Rejected, 'Reject', 'gray', Heroicon::OutlinedXCircle),
        ];
    }

    private function decisionAction(
        string $name,
        CommentStatus $status,
        string $label,
        string $color,
        Heroicon $icon,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Comment $record): bool => $record->status !== $status
                && (auth()->user()?->can('moderate_comment') ?? false))
            ->requiresConfirmation()
            ->action(function (Comment $record) use ($status, $label): void {
                app(ModerateComment::class)($record, $status);

                Notification::make()->success()->title($label)->send();

                $this->refreshFormData([]);
            });
    }
}
