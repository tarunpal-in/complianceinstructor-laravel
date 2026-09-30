<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * One setting, declared once.
 *
 * Deliberately describes the *value* and not the field. The order-number
 * section has live previews and cross-field validation; the promo bar hides
 * half its fields behind a toggle. A registry that also generated those would
 * be a worse version of Filament, so the form stays hand-written and this
 * carries what the form cannot: the key, its type, its default, which group
 * owns it, and therefore who may change it.
 *
 * That is enough for the three jobs worth having — seeding defaults, reading a
 * value with its declared fallback, and proving that nothing in the codebase
 * reads a setting no screen exposes.
 */
final class SettingDefinition
{
    public function __construct(
        /** Dotted "group.key", exactly as Setting::get is called with. */
        public readonly string $key,
        public readonly SettingGroup $group,
        public readonly string $label,
        /** The `type` column on the row: string, integer, boolean, color, media_id. */
        public readonly string $type,
        public readonly mixed $default = null,
        /** Why somebody would change it, for a screen and for whoever reads this next. */
        public readonly ?string $help = null,
        /**
         * Which control to draw: text, textarea, number, toggle, url, email,
         * tel, timezone.
         *
         * Separate from `type`, because the stored type does not imply the
         * input — an address and a trading name are both strings, and one of
         * them needs four lines. Only the generated groups read this; the
         * bespoke ones (order numbers, promo bar, brand) draw their own.
         */
        public readonly string $input = 'text',
        /** Characters a text input accepts, where a column bounds it. */
        public readonly ?int $maxLength = 255,
        /** @var array{0: int, 1: int}|null Bounds for a number input. */
        public readonly ?array $range = null,
    ) {}

    /**
     * The form state key.
     *
     * Dots would nest the value inside Filament's state path — `business.name`
     * becomes `data['business']['name']` — so they are flattened, matching the
     * underscored names the hand-written sections already use.
     */
    public function field(): string
    {
        return str_replace('.', '_', $this->key);
    }

    /** The stored value, or the declared default. */
    public function value(): mixed
    {
        return \App\Models\Setting::get($this->key, $this->default);
    }

    public function store(mixed $value): void
    {
        \App\Models\Setting::set($this->key, $value, $this->type);
    }
}
