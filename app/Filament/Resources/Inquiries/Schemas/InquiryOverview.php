<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Inquiry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One message, and enough context to answer it.
 *
 * The order is the whole design. Somebody opening this screen is about to write
 * a reply, so what was asked comes first and gets the room; everything else is
 * one line of context underneath. An earlier version of this page led with
 * three tiles about whether the person had ever bought anything and put the
 * message itself at the bottom of a section called "Where they came from" —
 * which is a lead-capture screen's priorities, not an inbox's.
 *
 * Read-only. Edit exists for correcting a mistyped address, not for rewriting
 * what somebody sent.
 */
class InquiryOverview
{
    public static function configure(Schema $schema): Schema
    {
        // One column of cards, stacked — the admin's single-column convention.
        // Field groupings inside a card keep their own columns.
        return $schema->columns(1)->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('name')
                    ->label('From')
                    ->weight('bold')
                    ->helperText(fn (Inquiry $record): ?string => $record->company),

                TextEntry::make('email')
                    ->label('Email')
                    ->copyable(),

                TextEntry::make('phone')
                    ->label('Phone')
                    ->placeholder('Not given')
                    ->copyable(),

                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Inquiry $record): string => $record->status->label())
                    ->color(fn (Inquiry $record): string => $record->status->color()),
            ]),

            // The point of the record.
            Section::make(fn (Inquiry $record): string => $record->subject ?? 'What they asked')
                ->schema([
                    TextEntry::make('message')
                        ->hiddenLabel()
                        ->placeholder('They left no message.')
                        ->prose(),
                ]),

            Section::make('Context')
                ->columns(3)
                ->schema([
                    /*
                     * One line, not a section. Whether they have bought before
                     * changes how a reply is written — an existing customer
                     * asking about seats is a different conversation — but it
                     * is a fact about the sender, not the subject of the page.
                     *
                     * Derived from their orders, never stored. See
                     * InquiryStatus for why there is no `converted` value.
                     */
                    TextEntry::make('customer')
                        ->label('Who they are')
                        ->badge()
                        ->state(fn (Inquiry $record): string => self::describeSender($record))
                        ->color(fn (Inquiry $record): string => $record->outcomeColor())
                        ->url(fn (Inquiry $record): ?string => $record->customer === null
                            ? null
                            : self::ordersUrl($record))
                        ->helperText('Worked out from their account and its orders.'),

                    TextEntry::make('source')
                        ->label('Arrived by')
                        ->badge()
                        ->state(fn (Inquiry $record): string => $record->source->label())
                        ->color(fn (Inquiry $record): string => $record->source->color()),

                    TextEntry::make('created_at')
                        ->label('Came in')
                        ->dateTime('j M Y, H:i')
                        ->helperText(fn (Inquiry $record): ?string => $record->created_at?->diffForHumans()),
                ]),

            /*
             * Repeats are the reason the email column carries no UNIQUE index.
             * Somebody writing again a month later is usually somebody who did
             * not get a satisfactory answer the first time, and whoever is
             * about to reply should know that before they start.
             */
            Section::make('They have written before')
                ->visible(fn (Inquiry $record): bool => $record->fromSameEmail()
                    ->whereKeyNot($record->getKey())
                    ->exists())
                ->schema([
                    TextEntry::make('fromSameEmail')
                        ->hiddenLabel()
                        ->state(fn (Inquiry $record): string => self::describeRepeats($record))
                        ->prose(),
                ]),

            Section::make('Handling')
                ->columns(3)
                ->schema([
                    TextEntry::make('assignedTo.name')
                        ->label('Owner')
                        ->placeholder('Nobody yet'),

                    TextEntry::make('contacted_at')
                        ->label('Last contacted')
                        ->dateTime('j M Y, H:i')
                        ->placeholder('Never'),

                    TextEntry::make('age')
                        ->label('Waiting')
                        ->state(fn (Inquiry $record): string => $record->isOpen()
                            ? $record->ageInDays().' days'
                            : 'Not waiting'),

                    TextEntry::make('note')
                        ->label('Notes')
                        ->placeholder('Nothing written')
                        ->prose()
                        ->columnSpanFull(),
                ]),

            /*
             * §9 makes these personal data, and they are kept for one reason:
             * telling the fourth identical message this morning from a real
             * person. Collapsed, because nobody opens an inquiry to read a user
             * agent string.
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

    /**
     * Who is writing, in a phrase.
     *
     * Three states worth distinguishing before answering: a stranger, somebody
     * with an account who has never bought, and a paying customer.
     */
    private static function describeSender(Inquiry $record): string
    {
        if (! $record->hasAccount()) {
            return 'Not a customer';
        }

        $settled = $record->customer?->orders()->settled()->count() ?? 0;

        return $settled === 0
            ? 'Registered, never bought'
            : trans_choice('Customer · :count order|Customer · :count orders', $settled, ['count' => $settled]);
    }

    /** Their orders, if whoever is reading may open that screen. */
    private static function ordersUrl(Inquiry $record): ?string
    {
        return OrderResource::canViewAny()
            ? OrderResource::getUrl('index', ['tableSearch' => $record->email])
            : null;
    }

    private static function describeRepeats(Inquiry $record): string
    {
        return $record->fromSameEmail()
            ->whereKeyNot($record->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Inquiry $other): string => ($other->describeSubject(60) ?? $other->source->label())
                .' — '.$other->created_at?->format('j M Y')
                .', '.$other->status->label())
            ->implode("\n");
    }
}
