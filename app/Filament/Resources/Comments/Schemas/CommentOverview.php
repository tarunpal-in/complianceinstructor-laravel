<?php

declare(strict_types=1);

namespace App\Filament\Resources\Comments\Schemas;

use App\Models\Comment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One comment, in full, with what is known about where it came from.
 *
 * Read-only. Publishing somebody's words is one decision; editing them and
 * leaving their name on it is a different and worse one, so there is no form
 * here and no Edit page on the resource.
 */
class CommentOverview
{
    public static function configure(Schema $schema): Schema
    {
        // One column of cards, stacked — the admin's single-column convention.
        // Field groupings inside a card keep their own columns.
        return $schema->columns(1)->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('author_name')
                    ->label('From')
                    ->weight('bold')
                    ->helperText(fn (Comment $record): string => $record->author_email),

                TextEntry::make('account')
                    ->label('Account')
                    ->state(fn (Comment $record): string => $record->author->name ?? 'Not signed in')
                    // Most commenters are guests. Saying so is better than a
                    // blank cell that reads like missing data.
                    ->helperText(fn (Comment $record): ?string => $record->author === null
                        ? 'Left as a guest.'
                        : null),

                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Comment $record): string => $record->status->label())
                    ->color(fn (Comment $record): string => $record->status->color()),

                TextEntry::make('created_at')
                    ->label('Left')
                    ->dateTime('j M Y, H:i')
                    ->helperText(fn (Comment $record): ?string => $record->created_at?->diffForHumans()),
            ]),

            Section::make('What they wrote')
                ->schema([
                    TextEntry::make('body')
                        ->hiddenLabel()
                        ->prose(),

                    TextEntry::make('spam_reason')
                        ->label('The filter thought')
                        ->badge()
                        ->color('danger')
                        ->visible(fn (Comment $record): bool => filled($record->spam_reason))
                        ->helperText('A guess, not a verdict. Publishing clears it.'),
                ]),

            Section::make('Where it sits')
                ->columns(3)
                ->schema([
                    TextEntry::make('post.title')
                        ->label('Article')
                        ->placeholder('Deleted article')
                        ->url(fn (Comment $record): ?string => $record->post?->viewableUrl())
                        ->openUrlInNewTab(),

                    TextEntry::make('parent.author_name')
                        ->label('In reply to')
                        ->placeholder('Nothing — top of a thread'),

                    TextEntry::make('replies_count')
                        ->label('Replies')
                        ->state(fn (Comment $record): int => $record->replies()->count()),
                ]),

            Section::make('How it was handled')
                ->visible(fn (Comment $record): bool => $record->moderated_at !== null)
                ->columns(2)
                ->schema([
                    TextEntry::make('moderated_at')->label('When')->dateTime('j M Y, H:i'),
                    TextEntry::make('moderatedBy.name')->label('By')->placeholder('The system'),
                ]),

            /*
             * §9 makes this personal data. It is kept because it is what lets a
             * moderator recognise the fourth comment from the same source this
             * morning — but it is put behind its own collapsed heading rather
             * than sitting in the summary, because that is not what anyone
             * opens this screen to read.
             */
            Section::make('Technical detail')
                ->description('Kept to recognise repeat senders. How long it is retained belongs in the privacy policy.')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextEntry::make('ip_address')->label('IP address')->placeholder('Not recorded')->copyable(),
                    TextEntry::make('user_agent')->label('Browser')->placeholder('Not recorded')->wrap(),
                ]),
        ]);
    }
}
