<?php

declare(strict_types=1);

namespace App\Filament\Resources\Certificates\Schemas;

use App\Models\Certificate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The certificate as it reads.
 *
 * Every field in the first section comes off the snapshot columns rather than
 * the relations, and the second section shows the records it was issued
 * *against* — so when a webinar has been retitled since, both are visible and
 * the difference is obvious rather than confusing.
 */
class CertificateOverview
{
    public static function configure(Schema $schema): Schema
    {
        // One column of cards, stacked — the admin's single-column convention.
        // Field groupings inside a card keep their own columns.
        return $schema->columns(1)->components([

            Section::make('The certificate')
                ->description('As issued. None of this changes afterwards, whatever happens to the records behind it.')
                ->columns(4)
                ->schema([
                    TextEntry::make('serial')
                        ->label('Serial')
                        ->copyable()
                        ->weight('bold')
                        ->helperText('What an accrediting body quotes when they ask.'),

                    TextEntry::make('learner_name')->label('Awarded to'),

                    TextEntry::make('credits')
                        ->label('Credits')
                        ->badge()
                        ->state(fn (Certificate $record): string => $record->describeCredits()),

                    TextEntry::make('state')
                        ->label('Status')
                        ->badge()
                        ->state(fn (Certificate $record): string => $record->describeState())
                        ->color(fn (Certificate $record): string => $record->stateColor()),

                    TextEntry::make('webinar_title')
                        ->label('For completing')
                        ->columnSpan(2),

                    TextEntry::make('issued_at')
                        ->label('Issued')
                        ->dateTime('j M Y, H:i'),

                    TextEntry::make('issuedBy.name')
                        ->label('By')
                        ->placeholder('The system'),
                ]),

            Section::make('Issued against')
                ->description('The live records. These can change; the certificate above cannot.')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('user.name')
                        ->label('Account')
                        ->placeholder('Deleted')
                        ->helperText(fn (Certificate $record): ?string => $record->user->email ?? null),

                    TextEntry::make('webinar.title')
                        ->label('Webinar now called')
                        ->placeholder('Deleted')
                        // Worth seeing side by side: a retitled webinar is the
                        // case the snapshot exists for, and support should be
                        // able to tell that story rather than guess at it.
                        ->helperText(fn (Certificate $record): ?string => $record->webinar?->title === $record->webinar_title
                            ? null
                            : 'Retitled since this certificate was issued.'),

                    TextEntry::make('accreditation.name')
                        ->label('Accrediting body')
                        ->placeholder('Deleted'),
                ]),

            Section::make('Revoked')
                ->visible(fn (Certificate $record): bool => $record->isRevoked())
                ->columns(3)
                ->schema([
                    TextEntry::make('revoked_at')->label('When')->dateTime('j M Y, H:i'),
                    TextEntry::make('revokedBy.name')->label('By')->placeholder('Not recorded'),
                    TextEntry::make('revoked_reason')->label('Why')->placeholder('No reason recorded'),
                ]),

            Section::make('Document')
                ->columns(2)
                ->schema([
                    TextEntry::make('pdf_path')
                        ->label('PDF')
                        ->placeholder('Not generated')
                        // §8 asks for a branded PDF on a private disk, served
                        // through a signed URL (§9). That needs headless Chrome
                        // (§11) and is not wired yet — said plainly rather than
                        // shown as a download that would fail.
                        ->helperText('The branded PDF is not generated yet. The certificate above is the record; the PDF is a rendering of it.'),
                ]),
        ]);
    }
}
