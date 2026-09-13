<?php

declare(strict_types=1);

namespace App\Filament\Resources\Entitlements\Schemas;

use App\Enums\EntitlementSource;
use App\Models\Entitlement;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One grant, and where it came from.
 *
 * Read-only. Access is granted by an act that records why — a capture, a
 * redemption, a staff decision — and the History list below is the record of
 * those acts.
 */
class EntitlementOverview
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(4)->schema([
                TextEntry::make('user.name')
                    ->label('Member')
                    ->weight('bold')
                    ->placeholder('Account deleted')
                    ->helperText(fn (Entitlement $record): ?string => $record->user->email ?? null),

                TextEntry::make('webinar.title')
                    ->label('Webinar')
                    ->placeholder('Webinar deleted'),

                TextEntry::make('format')
                    ->label('Format')
                    ->badge()
                    ->state(fn (Entitlement $record): string => $record->format->label()),

                TextEntry::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Entitlement $record): string => $record->describeState())
                    ->color(fn (Entitlement $record): string => $record->stateColor()),
            ]),

            Section::make('Where it came from')
                ->description('One of §5.2\'s three routes: a purchase, a membership allowance, or a decision by staff.')
                ->columns(4)
                ->schema([
                    TextEntry::make('source_type')
                        ->label('Source')
                        ->badge()
                        ->state(fn (Entitlement $record): string => $record->source_type->label())
                        ->color(fn (Entitlement $record): string => $record->source_type->color())
                        ->helperText(fn (Entitlement $record): string => $record->source_type->consumesAllowance()
                            ? 'Spent one of their allowances.'
                            : 'Cost them no allowance.'),

                    TextEntry::make('source')
                        ->label('Record')
                        ->state(fn (Entitlement $record): string => match ($record->source_type) {
                            EntitlementSource::Subscription => 'Membership #'.$record->source_id,
                            EntitlementSource::Order => 'Order #'.$record->source_id,
                            EntitlementSource::Manual => 'Given by staff',
                        }),

                    TextEntry::make('granted_at')
                        ->label('Granted')
                        ->dateTime('j M Y, H:i'),

                    TextEntry::make('expires_at')
                        ->label('Expires')
                        ->dateTime('j M Y')
                        // A membership grant lasts as long as the membership
                        // that paid for it; a staff comp has no end.
                        ->placeholder('Does not expire'),
                ]),

            Section::make('Given back')
                ->visible(fn (Entitlement $record): bool => $record->isRevoked())
                ->columns(3)
                ->schema([
                    TextEntry::make('revoked_at')->label('When')->dateTime('j M Y, H:i'),
                    TextEntry::make('revokedBy.name')->label('By')->placeholder('Not recorded'),
                    TextEntry::make('note')->label('Why')->placeholder('No reason recorded'),
                ]),
        ]);
    }
}
