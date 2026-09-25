<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscribers\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Subscriber;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One address, and the evidence behind it.
 *
 * The consent section is the reason this page exists rather than a row in a
 * list being enough: "when did this person agree to be emailed, and what were
 * they looking at when they did" is a question this client should be able to
 * answer about their own list, and it is answered from four columns that mean
 * little separately.
 *
 * Read-only throughout. Every value here is a decision with a date attached,
 * and a form over them would let somebody type a confirmation date for a
 * confirmation that never happened.
 */
class SubscriberOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('email')
                    ->label('Address')
                    ->weight('bold')
                    ->copyable(),

                TextEntry::make('name')
                    ->label('Name')
                    ->placeholder('Not given'),

                TextEntry::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Subscriber $record): string => $record->status->label())
                    ->color(fn (Subscriber $record): string => $record->status->color()),

                TextEntry::make('customer')
                    ->label('Account')
                    ->state(fn (Subscriber $record): string => $record->customer === null
                        ? 'No account on this address'
                        : 'Registered customer')
                    ->url(fn (Subscriber $record): ?string => $record->customer === null || ! OrderResource::canViewAny()
                        ? null
                        : OrderResource::getUrl('index', ['tableSearch' => $record->email]))
                    ->color(fn (Subscriber $record): string => $record->customer === null ? 'gray' : 'primary'),
            ]),

            Section::make('Consent')
                ->description('What this business can show if it is ever asked why it emailed somebody.')
                ->columns(4)
                ->schema([
                    TextEntry::make('source')
                        ->label('Signed up')
                        ->badge()
                        ->state(fn (Subscriber $record): string => $record->source->label())
                        ->color(fn (Subscriber $record): string => $record->source->color())
                        ->helperText(fn (Subscriber $record): ?string => $record->source->isSelfSubmitted()
                            ? null
                            : 'Not a consent collected by this site.'),

                    TextEntry::make('subscribed_at')
                        ->label('When')
                        ->dateTime('j M Y, H:i')
                        ->placeholder('Not recorded'),

                    TextEntry::make('confirmed_at')
                        ->label('Confirmed')
                        ->dateTime('j M Y, H:i')
                        // Nothing to confirm while double opt-in is off, which
                        // is the state until mail is wired — so say that rather
                        // than leaving a blank that reads like a failure.
                        ->placeholder(fn (): string => Subscriber::requiresDoubleOptIn()
                            ? 'Never confirmed'
                            : 'Confirmation is not switched on'),

                    TextEntry::make('consent_ip')
                        ->label('From')
                        ->placeholder('Not recorded')
                        ->copyable(),

                    TextEntry::make('consent_user_agent')
                        ->label('Browser at the time')
                        ->placeholder('Not recorded')
                        ->wrap()
                        ->columnSpanFull(),
                ]),

            /*
             * Only rendered once there is something to say. A section headed
             * "Why they are off the list" on somebody happily subscribed reads
             * like a fact about them.
             */
            Section::make('Why they are off the list')
                ->visible(fn (Subscriber $record): bool => $record->isSuppressed())
                ->columns(3)
                ->schema([
                    TextEntry::make('unsubscribed_at')
                        ->label('Unsubscribed')
                        ->dateTime('j M Y, H:i')
                        ->placeholder('Not applicable'),

                    TextEntry::make('unsubscribe_reason')
                        ->label('Reason')
                        ->placeholder('None given'),

                    TextEntry::make('bounced_at')
                        ->label('Last bounced')
                        ->dateTime('j M Y, H:i')
                        ->placeholder('Never')
                        ->helperText(fn (Subscriber $record): ?string => $record->bounce_reason),
                ]),

            Section::make('Their unsubscribe link')
                ->description('The link that goes in the footer of every campaign. Signed, and it does not expire, because people read old email.')
                ->collapsed()
                ->schema([
                    TextEntry::make('unsubscribe_url')
                        ->hiddenLabel()
                        ->state(fn (Subscriber $record): string => $record->unsubscribeUrl())
                        ->copyable()
                        ->wrap(),
                ]),
        ]);
    }
}
