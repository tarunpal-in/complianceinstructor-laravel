<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which menu a navigation item belongs to — CLAUDE.md §5.6.
 */
enum NavigationMenu: string
{
    case Header = 'header';
    case Footer = 'footer';

    public function label(): string
    {
        return match ($this) {
            self::Header => 'Header menu',
            self::Footer => 'Footer menu',
        };
    }
}
