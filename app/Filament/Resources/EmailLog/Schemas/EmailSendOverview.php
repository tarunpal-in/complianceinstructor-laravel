<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLog\Schemas;

use App\Models\EmailSend;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class EmailSendOverview
{
    public static function configure(Schema $schema): Schema
    {
        // One column of cards, stacked — the admin's single-column convention.
        // Field groupings inside a card keep their own columns.
        return $schema->columns(1)->components([
            Section::make('What happened')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (\App\Enums\EmailStatus $state): string => $state->label())
                        ->color(fn (\App\Enums\EmailStatus $state): string => $state->color()),

                    TextEntry::make('created_at')->label('Created')->dateTime('j M Y, H:i'),
                    TextEntry::make('sent_at')->label('Handed to Gmail')->dateTime('j M Y, H:i')->placeholder('—'),

                    TextEntry::make('outcome_note')
                        ->label('Why')
                        ->columnSpanFull()
                        ->visible(fn (EmailSend $record): bool => filled($record->outcome_note)),

                    TextEntry::make('error')
                        ->label('Error reported by the mail server')
                        ->color('danger')
                        ->columnSpanFull()
                        ->visible(fn (EmailSend $record): bool => filled($record->error)),
                ]),

            Section::make('The email')
                ->columns(2)
                ->schema([
                    TextEntry::make('email_key')
                        ->label('Which email')
                        ->state(fn (EmailSend $record): string => $record->name()),

                    TextEntry::make('category')
                        ->label('Kind')
                        ->formatStateUsing(fn (\App\Enums\EmailCategory $state): string => $state->label()),

                    TextEntry::make('to_email')->label('To')->copyable(),
                    TextEntry::make('to_name')->label('Addressed as')->placeholder('—'),
                    TextEntry::make('subject')->label('Subject')->columnSpanFull(),
                    TextEntry::make('reply_to')->label('Reply-to')->placeholder('—'),

                    TextEntry::make('bcc')
                        ->label('Blind copy')
                        ->state(fn (EmailSend $record): string => implode(', ', $record->bcc ?? []) ?: '—'),
                ]),

            /*
             * The Message-ID, prominently, with a copy button.
             *
             * It is the only thread back to a bounce on this setup. A consumer
             * Gmail account has no webhook and no admin log search, so nothing
             * will ever tell us a message bounced — the bounce arrives as an
             * ordinary email in the sending mailbox quoting this id, and this is
             * how somebody matches it to the row in front of them.
             */
            Section::make('Tracing it')
                ->description('Gmail accepting a message is not the same as delivering it. If somebody says they never received this, find the bounce message in the sending mailbox by searching for the id below.')
                ->columns(2)
                ->schema([
                    TextEntry::make('message_id')
                        ->label('Message ID')
                        ->copyable()
                        ->placeholder('Never sent, so there is no id.')
                        ->columnSpanFull(),

                    TextEntry::make('mailer')->label('Sent via')->placeholder('—'),
                    TextEntry::make('attempts')->label('Attempts'),

                    TextEntry::make('resent_from_id')
                        ->label('This is a resend of')
                        ->state(fn (EmailSend $record): string => $record->resent_from_id === null
                            ? '—'
                            : 'Email #'.$record->resent_from_id)
                        ->url(fn (EmailSend $record): ?string => $record->resent_from_id === null
                            ? null
                            : \App\Filament\Resources\EmailLog\EmailSendResource::getUrl('view', [
                                'record' => $record->resent_from_id,
                            ], panel: 'admin')),

                    TextEntry::make('resentBy.name')->label('Resent by')->placeholder('—'),
                ]),

            Section::make('Content')
                ->description(fn (EmailSend $record): ?string => $record->bodyAbsenceReason())
                ->schema([
                    /*
                     * Rendered in a sandboxed iframe, never inlined.
                     *
                     * This is stored HTML, and the settings audit found a stored
                     * XSS through exactly this assumption — a value that the form
                     * validated and the reader did not. The sandbox attribute with
                     * no allow-scripts is the guard; an admin screen is not a safe
                     * place to execute somebody else's markup.
                     */
                    View::make('filament.email-log.body')
                        ->visible(fn (EmailSend $record): bool => $record->hasBody()),

                    Text::make(fn (EmailSend $record): string => (string) $record->bodyAbsenceReason())
                        ->visible(fn (EmailSend $record): bool => ! $record->hasBody()),
                ]),
        ]);
    }
}
