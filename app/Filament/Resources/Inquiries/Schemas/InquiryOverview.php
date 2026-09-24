<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Inquiry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One inquiry, and what became of them.
 *
 * The Outcome section is the reason this page exists rather than the edit form
 * being enough: it answers "did this ever turn into anything" by looking at the
 * account and its orders, which is a question no column on `inquiries` can be
 * trusted to answer.
 */
class InquiryOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('name')
                    ->label('Name')
                    ->weight('bold')
                    ->helperText(fn (Inquiry $record): ?string => $record->describeRole()),

                TextEntry::make('email')
                    ->label('Email')
                    ->copyable()
                    ->helperText(fn (Inquiry $record): string => $record->marketing_opt_in
                        ? 'Agreed to be emailed.'
                        : 'Has not agreed to marketing email.'),

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

            Section::make('What became of them')
                ->description('Worked out from their account and its orders — never from a column on this row.')
                ->columns(3)
                ->schema([
                    TextEntry::make('outcome')
                        ->label('Outcome')
                        ->badge()
                        ->state(fn (Inquiry $record): string => $record->describeOutcome())
                        ->color(fn (Inquiry $record): string => $record->outcomeColor()),

                    TextEntry::make('account')
                        ->label('Account')
                        ->state(fn (Inquiry $record): string => $record->customer === null
                            ? 'No account on this address'
                            : 'Registered '.$record->customer->created_at?->format('j M Y'))
                        // The customer list filtered to them, rather than their
                        // edit screen: this link is followed by support and
                        // finance, who can read an account without being able
                        // to change one.
                        ->url(fn (Inquiry $record): ?string => self::linkTo(UserResource::class, $record))
                        ->color(fn (Inquiry $record): string => $record->customer === null ? 'gray' : 'primary'),

                    TextEntry::make('orders')
                        ->label('Orders')
                        ->state(fn (Inquiry $record): string => self::describeOrders($record))
                        ->url(fn (Inquiry $record): ?string => self::linkTo(OrderResource::class, $record))
                        ->color(fn (Inquiry $record): string => $record->customer === null ? 'gray' : 'primary'),
                ]),

            Section::make('Where they came from')
                ->columns(4)
                ->schema([
                    TextEntry::make('source')
                        ->label('Source')
                        ->badge()
                        ->state(fn (Inquiry $record): string => $record->source->label())
                        ->color(fn (Inquiry $record): string => $record->source->color()),

                    TextEntry::make('source_detail')
                        ->label('Which one')
                        ->placeholder('Not recorded'),

                    TextEntry::make('industry.name')
                        ->label('Industry')
                        ->placeholder('Not given'),

                    TextEntry::make('created_at')
                        ->label('Came in')
                        ->dateTime('j M Y, H:i')
                        ->helperText(fn (Inquiry $record): ?string => $record->created_at?->diffForHumans()),

                    TextEntry::make('message')
                        ->label('What they said')
                        ->placeholder('Nothing written')
                        ->prose()
                        ->columnSpanFull(),
                ]),

            /*
             * Repeats are evidence, which is why the email is not unique. A
             * second inquiry three months later says the first campaign did not
             * close them and the second nearly did.
             */
            Section::make('Other inquiries from this address')
                ->visible(fn (Inquiry $record): bool => $record->fromSameEmail()->whereKeyNot($record->getKey())->exists())
                ->schema([
                    TextEntry::make('inquiries')
                        ->hiddenLabel()
                        ->state(fn (Inquiry $record): string => self::describeRepeats($record))
                        ->prose(),
                ]),

            Section::make('Follow-up')
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
                        ->label('Age')
                        ->state(fn (Inquiry $record): string => $record->ageInDays().' days'),

                    TextEntry::make('note')
                        ->label('Notes')
                        ->placeholder('Nothing written')
                        ->prose()
                        ->columnSpanFull(),
                ]),

            /*
             * §9 makes the IP and user agent personal data, and the campaign
             * fields are only read by a report. Collapsed rather than in the
             * summary, because neither is what anybody opens this page for.
             */
            Section::make('Campaign and technical detail')
                ->description('How long this is kept belongs in the privacy policy.')
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextEntry::make('utm_source')->label('Source')->placeholder('—'),
                    TextEntry::make('utm_medium')->label('Medium')->placeholder('—'),
                    TextEntry::make('utm_campaign')->label('Campaign')->placeholder('—'),
                    TextEntry::make('ip_address')->label('IP address')->placeholder('Not recorded'),
                    TextEntry::make('user_agent')->label('Browser')->placeholder('Not recorded')->wrap()->columnSpan(2),
                ]),
        ]);
    }

    /**
     * That resource's list, searched for this email.
     *
     * Null when there is no account to look at, and null when the person
     * reading cannot open that resource — a link to a page that answers 403 is
     * worse than no link.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private static function linkTo(string $resource, Inquiry $record): ?string
    {
        if ($record->customer === null || ! $resource::canViewAny()) {
            return null;
        }

        return $resource::getUrl('index', ['tableSearch' => $record->email]);
    }

    private static function describeOrders(Inquiry $record): string
    {
        if ($record->customer === null) {
            return 'No account, so nothing to look at';
        }

        $settled = $record->customer->orders()->settled()->count();

        return $settled === 0
            ? 'Registered but has never bought'
            : trans_choice(':count settled order|:count settled orders', $settled, ['count' => $settled]);
    }

    private static function describeRepeats(Inquiry $record): string
    {
        $others = $record->fromSameEmail()
            ->whereKeyNot($record->getKey())
            ->orderByDesc('created_at')
            ->get();

        return $others
            ->map(fn (Inquiry $other): string => $other->source->label()
                .' on '.$other->created_at?->format('j M Y')
                .' — '.$other->status->label())
            ->implode("\n");
    }
}
