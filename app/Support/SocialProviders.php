<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The social sign-in providers the customer login offers — §9's config-gating.
 *
 * A friendly URL slug (google/linkedin/microsoft) maps to the Socialite driver
 * and the `config/services` key that holds its credentials. A provider counts as
 * enabled only when both its id and secret are set, so a button never shows for a
 * provider that would error on click — the same rule the payment gateways follow.
 */
final class SocialProviders
{
    /** @var array<string, array{driver: string, label: string, service: string}> */
    private const PROVIDERS = [
        'google' => ['driver' => 'google', 'label' => 'Google', 'service' => 'google'],
        'linkedin' => ['driver' => 'linkedin-openid', 'label' => 'LinkedIn', 'service' => 'linkedin-openid'],
        'microsoft' => ['driver' => 'microsoft', 'label' => 'Microsoft', 'service' => 'microsoft'],
    ];

    public static function exists(string $slug): bool
    {
        return isset(self::PROVIDERS[$slug]);
    }

    public static function isEnabled(string $slug): bool
    {
        $provider = self::PROVIDERS[$slug] ?? null;

        if ($provider === null) {
            return false;
        }

        return filled(config("services.{$provider['service']}.client_id"))
            && filled(config("services.{$provider['service']}.client_secret"));
    }

    public static function driver(string $slug): string
    {
        return self::PROVIDERS[$slug]['driver'] ?? $slug;
    }

    public static function label(string $slug): string
    {
        return self::PROVIDERS[$slug]['label'] ?? ucfirst($slug);
    }

    /**
     * The enabled providers, as slug => label, for rendering the buttons.
     *
     * @return array<string, string>
     */
    public static function enabled(): array
    {
        $enabled = [];

        foreach (self::PROVIDERS as $slug => $provider) {
            if (self::isEnabled($slug)) {
                $enabled[$slug] = $provider['label'];
            }
        }

        return $enabled;
    }
}
