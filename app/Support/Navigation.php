<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NavigationMenu;
use App\Models\NavigationItem;
use Illuminate\Support\Collection;

/**
 * The header and footer menus for the front end — CLAUDE.md §5.6.
 *
 * Reads `navigation_items` (built in the admin navigation manager) and returns a
 * normalised tree the navbar and footer iterate, so the views never touch the
 * model. When a menu has no rows yet — a fresh install, or before the client has
 * touched it — it falls back to the menus that were hardcoded before this
 * feature, so nothing disappears in the gap (the same graceful-default approach
 * the homepage sections use).
 *
 * Each node is ['label', 'url', 'new_tab', 'children'] — a parent with children
 * is a header dropdown or a footer column; a parent whose url is null is a
 * trigger or a column heading, not a link.
 */
final class Navigation
{
    /** @return list<array<string, mixed>> */
    public static function header(): array
    {
        return self::tree(NavigationMenu::Header) ?? self::defaultHeader();
    }

    /** @return list<array<string, mixed>> */
    public static function footer(): array
    {
        return self::tree(NavigationMenu::Footer) ?? self::defaultFooter();
    }

    /**
     * The active tree for a menu, or null when it has no rows (use the default).
     *
     * @return list<array<string, mixed>>|null
     */
    private static function tree(NavigationMenu $menu): ?array
    {
        $items = NavigationItem::query()
            ->forMenu($menu)
            ->active()
            ->topLevel()
            ->with(['children' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        return $items->map(fn (NavigationItem $item): array => self::node($item))->all();
    }

    /** @return array{label: string, url: ?string, new_tab: bool, children: list<array<string, mixed>>} */
    private static function node(NavigationItem $item): array
    {
        /** @var Collection<int, NavigationItem> $children */
        $children = $item->relationLoaded('children') ? $item->children : new Collection;

        return [
            'label' => $item->label,
            'url' => self::normalizeUrl($item->url),
            'new_tab' => $item->open_in_new_tab,
            'children' => $children->map(fn (NavigationItem $child): array => [
                'label' => $child->label,
                'url' => self::normalizeUrl($child->url),
                'new_tab' => $child->open_in_new_tab,
                'children' => [],
            ])->all(),
        ];
    }

    /**
     * Keep a menu link from breaking on sub-paths.
     *
     * A bare path an admin typed without a leading slash — "webinars?type=live"
     * instead of "/webinars?type=live" — is relative, so on /contact it resolves
     * to /contact/webinars and 404s. Absolute URLs, schemes (mailto:, tel:,
     * https:), protocol-relative links, root-relative paths and in-page anchors
     * are already fine and left untouched.
     */
    private static function normalizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*:|//|/|\#)#i', $url) === 1) {
            return $url;
        }

        return '/'.$url;
    }

    /** @return list<array<string, mixed>> */
    public static function defaultHeader(): array
    {
        return [
            ['label' => 'Home', 'url' => '/', 'new_tab' => false, 'children' => []],
            ['label' => 'About', 'url' => '/about', 'new_tab' => false, 'children' => []],
            ['label' => 'Training', 'url' => '/webinars', 'new_tab' => false, 'children' => []],
            ['label' => 'Industry', 'url' => '/industries', 'new_tab' => false, 'children' => []],
            ['label' => 'Speakers', 'url' => '/speakers', 'new_tab' => false, 'children' => []],
            ['label' => 'Memberships', 'url' => '/membership', 'new_tab' => false, 'children' => []],
            ['label' => 'Blog', 'url' => '/blog', 'new_tab' => false, 'children' => []],
            ['label' => 'Help', 'url' => '/help', 'new_tab' => false, 'children' => []],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function defaultFooter(): array
    {
        return [
            self::column('Company', [
                ['About Us', '/about'], ['Speakers', '/speakers'], ['Blog', '/blog'], ['Careers', '/careers'], ['Press', '/press'],
            ]),
            self::column('Training', [
                ['Live Webinars', '/webinars?type=live'], ['Recorded Webinars', '/webinars?type=recorded'], ['Packages', '/webinars'], ['Memberships', '/membership'],
            ]),
            self::column('Resources', [
                ['FAQ', '/faq'], ['Help Center', '/help'], ['Contact', '/contact'],
            ]),
            self::column('Legal', [
                ['Terms of Service', '/terms-of-service'], ['Privacy Policy', '/privacy-policy'], ['Refund Policy', '/refund-policy'], ['Cookie Policy', '/cookie-policy'],
            ]),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $links
     * @return array<string, mixed>
     */
    private static function column(string $heading, array $links): array
    {
        return [
            'label' => $heading,
            'url' => null,
            'new_tab' => false,
            'children' => array_map(
                fn (array $link): array => ['label' => $link[0], 'url' => $link[1], 'new_tab' => false, 'children' => []],
                $links,
            ),
        ];
    }
}
