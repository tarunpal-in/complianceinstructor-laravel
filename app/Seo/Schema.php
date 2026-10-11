<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\Media;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Webinar;
use App\Support\Business;
use Illuminate\Support\Str;

/**
 * Structured data (JSON-LD) — CLAUDE.md §5.6, client SEO brief §11.
 *
 * Builds the schema.org types the brief asks for, each as a plain array the
 * `partials/schema` view encodes. Organization and WebSite describe the site and
 * go on every page; Article, Event and the offers describe one record and are
 * passed from its controller.
 *
 * The brief is firm that **schema must only state what is actually on the page**
 * — Google penalises structured data that claims more than the page shows. So
 * every value here comes from a real field, and anything missing is omitted
 * rather than guessed: a webinar with no speaker simply has no `performer`.
 */
final class Schema
{
    /**
     * The organisation behind the site. On every page.
     *
     * @return array<string, mixed>
     */
    public static function organization(): array
    {
        $data = [
            '@type' => 'Organization',
            '@id' => Seo::absoluteUrl('/').'#organization',
            'name' => Business::name(),
            'url' => Seo::absoluteUrl('/'),
        ];

        if (($logo = self::logoUrl()) !== null) {
            $data['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }

        $sameAs = array_values(Business::socials());
        if ($sameAs !== []) {
            $data['sameAs'] = $sameAs;
        }

        $contact = array_filter([
            'telephone' => Business::phone(),
            'email' => Business::email(),
        ]);
        if ($contact !== []) {
            $data['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support', ...$contact];
        }

        return self::ld($data);
    }

    /**
     * The website itself, with the on-site search action — this is what can earn
     * a search box directly in Google's results. On every page.
     *
     * @return array<string, mixed>
     */
    public static function website(): array
    {
        return self::ld([
            '@type' => 'WebSite',
            '@id' => Seo::absoluteUrl('/').'#website',
            'name' => Business::name(),
            'url' => Seo::absoluteUrl('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => Seo::absoluteUrl('/blog').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    /**
     * A breadcrumb trail, matching the on-page breadcrumbs.
     *
     * @param  array<int, array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        $position = 1;

        return self::ld([
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                fn (array $item): array => [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ],
                $items,
            ),
        ]);
    }

    /**
     * A blog article.
     *
     * @return array<string, mixed>
     */
    public static function article(Post $post): array
    {
        $data = [
            '@type' => 'Article',
            'headline' => Str::limit($post->title, 110, ''),
            'description' => $post->summary(160),
            'url' => Seo::absoluteUrl($post->path()),
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'author' => filled($post->author?->name)
                ? ['@type' => 'Person', 'name' => $post->author->name]
                : null,
            'publisher' => self::publisher(),
        ];

        if (($image = $post->defaultSeoOgImageUrl()) !== null) {
            $data['image'] = $image;
        }

        return self::ld($data);
    }

    /**
     * A webinar as an online Event, with its sellable formats as offers.
     *
     * @return array<string, mixed>
     */
    public static function event(Webinar $webinar): array
    {
        $url = Seo::absoluteUrl('/webinars/'.$webinar->slug);

        $data = [
            '@type' => 'Event',
            'name' => $webinar->title,
            'description' => Str::limit(strip_tags((string) ($webinar->summary ?: $webinar->description)), 300, ''),
            'url' => $url,
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'eventStatus' => 'https://schema.org/EventScheduled',
            'startDate' => $webinar->scheduled_at?->toIso8601String(),
            'endDate' => $webinar->scheduled_at !== null && $webinar->duration_minutes !== null
                ? $webinar->scheduled_at->copy()->addMinutes($webinar->duration_minutes)->toIso8601String()
                : null,
            'location' => [
                '@type' => 'VirtualLocation',
                'url' => $url,
            ],
            'organizer' => [
                '@type' => 'Organization',
                'name' => Business::name(),
                'url' => Seo::absoluteUrl('/'),
            ],
            'performer' => filled($webinar->speaker?->name)
                ? ['@type' => 'Person', 'name' => $webinar->speaker->name]
                : null,
        ];

        if (($image = $webinar->defaultSeoOgImageUrl()) !== null) {
            $data['image'] = $image;
        }

        $offers = self::offers($webinar, $url);
        if ($offers !== []) {
            $data['offers'] = $offers;
        }

        return self::ld($data);
    }

    /**
     * The sellable formats of a webinar, as schema.org Offers.
     *
     * Prices come straight from `offerings.price_cents` — the one source of
     * price (§5.3) — formatted as a decimal string, never a float.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function offers(Webinar $webinar, string $url): array
    {
        return $webinar->offerings
            ->where('is_active', true)
            ->map(fn ($offering): array => [
                '@type' => 'Offer',
                'price' => number_format($offering->price_cents / 100, 2, '.', ''),
                'priceCurrency' => $offering->currency,
                'availability' => 'https://schema.org/InStock',
                'url' => $url,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private static function publisher(): array
    {
        $data = [
            '@type' => 'Organization',
            'name' => Business::name(),
        ];

        if (($logo = self::logoUrl()) !== null) {
            $data['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }

        return $data;
    }

    private static function logoUrl(): ?string
    {
        $id = Setting::mediaId('brand.logo_media_id');

        if ($id === null) {
            return null;
        }

        return Media::query()->find($id)?->detailImageUrl();
    }

    /**
     * Stamp the context and drop null/empty values, so no array ever claims a
     * field the page cannot back up.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function ld(array $data): array
    {
        return ['@context' => 'https://schema.org', ...array_filter(
            $data,
            fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        )];
    }
}
