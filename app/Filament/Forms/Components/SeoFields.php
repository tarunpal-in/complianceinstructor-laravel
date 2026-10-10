<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * The SEO panel, shared by every content resource.
 *
 * One component on six forms, so "where do I edit SEO" is the same place and the
 * same shape whether it is a webinar, an article or a page — the brief's Main
 * Goal (SEO managed from the CMS, by one team, without a developer) depends on
 * that consistency.
 *
 * Every field is an override with a stated fallback, so the common case is to
 * leave it blank. The section is collapsed by default: it sits beneath the
 * content, where somebody goes when they want it, rather than competing with the
 * title and body for the writer's attention.
 *
 * **No field writes a meta-keywords tag.** The focus keyword is captured for the
 * SEO team's own tracking and the help text says so; the renderer has no path to
 * emit it, and a test proves that.
 */
final class SeoFields
{
    public static function make(): Section
    {
        return Section::make('Search engine optimisation')
            ->description('How this page appears in Google and when shared. Every field is optional — leave one blank and a sensible default is used.')
            ->icon('heroicon-o-magnifying-glass')
            ->collapsed()
            // Saves into the polymorphic seo_meta row. Filament creates it on
            // first save and updates it after.
            ->relationship('seo')
            ->columns(2)
            ->schema([
                TextInput::make('title')
                    ->label('Meta title')
                    ->maxLength(70)
                    ->live(onBlur: true)
                    ->helperText('What shows as the blue link in search results. Aim for 50–60 characters. Blank uses the page\'s own title.')
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Meta description')
                    ->rows(2)
                    ->maxLength(160)
                    ->live(onBlur: true)
                    ->helperText('The grey summary under the link. Aim for 150–160 characters. Blank uses the page\'s summary.')
                    ->columnSpanFull(),

                // The live snippet. Shows what the typed title and description
                // will look like as a result, which is the one bit of feedback
                // that stops titles being written too long to read.
                Placeholder::make('seo_preview')
                    ->label('Search result preview')
                    ->content(fn (Get $get): Htmlable => self::preview($get))
                    ->columnSpanFull(),

                TextInput::make('keywords')
                    ->label('Focus keyword')
                    ->maxLength(120)
                    // Said plainly, because the brief is emphatic: this is
                    // internal and must never become a meta tag.
                    ->helperText('For your own SEO tracking only. It is never shown to search engines or added to the page.'),

                Select::make('robots')
                    ->label('Search engines')
                    ->options([
                        'index, follow' => 'Index this page (default)',
                        'noindex, follow' => 'Hide from search (noindex)',
                    ])
                    ->default('index, follow')
                    ->selectablePlaceholder(false)
                    ->helperText('"Hide" keeps the page off Google and out of the sitemap.'),

                MediaPicker::make('og_image_media_id')
                    ->label('Social share image')
                    ->helperText('Shown when the page is shared on LinkedIn or elsewhere. Blank uses the page\'s own main image.')
                    ->columnSpanFull(),

                TextInput::make('canonical')
                    ->label('Canonical URL')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('Leave blank')
                    // Advanced and rarely needed, so it is last and clearly
                    // optional. Getting it wrong deindexes a page, so the help
                    // text says when to touch it: almost never.
                    ->helperText('Advanced. Only set this to point search engines at a different URL as the original. Blank means this page is its own canonical.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The result snippet, rendered live from the two fields above.
     *
     * Where a field is blank it says which default will stand in rather than
     * showing nothing, so the editor can see that leaving it empty is a choice
     * with a known result. The record's own title is not read here — it lives at
     * a different attribute per type (`title` vs `name`) — so the fallback is
     * described rather than computed; the live page renders the real one.
     */
    private static function preview(Get $get): Htmlable
    {
        $title = trim((string) $get('title'));
        $description = trim((string) $get('description'));

        return new HtmlString(view('filament.forms.seo-preview', [
            'title' => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
        ])->render());
    }
}
