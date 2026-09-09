<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderNumberReset;
use Illuminate\Support\Carbon;

/**
 * The client-editable order number pattern.
 *
 * Tokens are `{YYYY}`, `{YY}`, `{MM}`, `{DD}` and `{SEQ}`, so
 * `{YYYY}-{MM}-{DD}-{SEQ}` with three digits gives `2024-10-05-001`. Everything
 * outside a token is literal, which is how a prefix like `CI-` works without
 * needing a field of its own.
 *
 * The class exists mainly to say no. A format is only safe if the reset period
 * is fully expressed in it — a daily reset with no day in the number produces a
 * duplicate on the second day, and `orders.number` is UNIQUE, so that surfaces
 * as a failed checkout rather than a cosmetic problem.
 */
readonly class OrderNumberFormat
{
    public const DEFAULT_PATTERN = 'CI-{YYYY}-{SEQ}';

    public const MIN_PADDING = 1;

    public const MAX_PADDING = 8;

    /** Every token the pattern understands. */
    public const TOKENS = ['YYYY', 'YY', 'MM', 'DD', 'SEQ'];

    public function __construct(
        public string $pattern,
        public int $padding,
        public OrderNumberReset $reset,
    ) {}

    /**
     * Build the number for a given sequence value.
     */
    public function render(int $sequence, ?Carbon $at = null): string
    {
        $at ??= now();

        return str_replace(
            ['{YYYY}', '{YY}', '{MM}', '{DD}', '{SEQ}'],
            [
                $at->format('Y'),
                $at->format('y'),
                $at->format('m'),
                $at->format('d'),
                str_pad((string) $sequence, $this->padding, '0', STR_PAD_LEFT),
            ],
            $this->pattern,
        );
    }

    /**
     * Everything wrong with this combination, in words the client can act on.
     *
     * @return array<int, string>
     */
    public function problems(): array
    {
        $problems = [];

        if (trim($this->pattern) === '') {
            return ['The format cannot be empty.'];
        }

        foreach ($this->unknownTokens() as $token) {
            $problems[] = "{{$token}} is not a token. Use ".implode(', ', array_map(
                static fn (string $known): string => '{'.$known.'}',
                self::TOKENS,
            )).'.';
        }

        if (! $this->hasToken('SEQ')) {
            $problems[] = 'The format must include {SEQ}, or every order would get the same number.';
        }

        if ($this->padding < self::MIN_PADDING || $this->padding > self::MAX_PADDING) {
            $problems[] = 'Sequence digits must be between '.self::MIN_PADDING.' and '.self::MAX_PADDING.'.';
        }

        foreach ($this->reset->requiredTokens() as $alternatives) {
            if ($this->hasAnyToken($alternatives)) {
                continue;
            }

            $problems[] = sprintf(
                'Resetting %s needs %s in the format, otherwise two orders in different periods would get the same number.',
                $this->reset === OrderNumberReset::Daily ? 'every day'
                    : ($this->reset === OrderNumberReset::Monthly ? 'every month' : 'every year'),
                implode(' or ', array_map(static fn (string $token): string => '{'.$token.'}', $alternatives)),
            );
        }

        return $problems;
    }

    public function isValid(): bool
    {
        return $this->problems() === [];
    }

    /**
     * What the next few numbers will look like, for the settings preview.
     *
     * Showing three rather than one is what makes a wrong reset period obvious:
     * the client sees the sequence advancing and can tell at a glance whether
     * that is what they meant.
     *
     * @return array<int, string>
     */
    public function preview(int $from = 1, int $count = 3, ?Carbon $at = null): array
    {
        $at ??= now();

        return array_map(
            fn (int $offset): string => $this->render($from + $offset, $at),
            range(0, max(0, $count - 1)),
        );
    }

    /** @return array<int, string> */
    private function unknownTokens(): array
    {
        preg_match_all('/\{([A-Za-z0-9_]*)\}/', $this->pattern, $matches);

        return array_values(array_unique(array_diff($matches[1], self::TOKENS)));
    }

    private function hasToken(string $token): bool
    {
        return str_contains($this->pattern, '{'.$token.'}');
    }

    /** @param  array<int, string>  $tokens */
    private function hasAnyToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->hasToken($token)) {
                return true;
            }
        }

        return false;
    }
}
