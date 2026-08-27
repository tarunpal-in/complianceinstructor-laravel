<?php

declare(strict_types=1);

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the user-menu avatar as an inline SVG data URI.
 *
 * Filament's default (UiAvatarsProvider) builds a URL against ui-avatars.com,
 * which sends every admin's name to a third party on every page load and makes
 * a core piece of admin chrome depend on an external host being reachable.
 * Neither is appropriate for a platform handling learner and payment data.
 *
 * Deterministic: the same name always yields the same colour.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    /**
     * Palette chosen to stay legible against white text in both themes.
     *
     * @var array<int, string>
     */
    private const COLORS = [
        '#1e40af', '#0f766e', '#9333ea', '#b45309',
        '#be123c', '#4338ca', '#047857', '#7c2d12',
    ];

    public function get(Model|Authenticatable $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        return 'data:image/svg+xml;base64,'.base64_encode(
            $this->svg($this->initials($name), $this->color($name))
        );
    }

    private function initials(string $name): string
    {
        $segments = array_values(array_filter(preg_split('/\s+/', $name) ?: []));

        if ($segments === []) {
            return '?';
        }

        $first = mb_substr($segments[0], 0, 1);
        $last = count($segments) > 1 ? mb_substr($segments[count($segments) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    private function color(string $name): string
    {
        return self::COLORS[abs(crc32($name)) % count(self::COLORS)];
    }

    private function svg(string $initials, string $background): string
    {
        $text = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
                <rect width="100" height="100" fill="{$background}"/>
                <text x="50" y="50" fill="#ffffff" font-family="system-ui, -apple-system, Segoe UI, sans-serif"
                      font-size="40" font-weight="600" text-anchor="middle" dominant-baseline="central">{$text}</text>
            </svg>
            SVG;
    }
}
