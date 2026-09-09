<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderNumberReset;
use Illuminate\Support\Carbon;

/**
 * The client-editable order number pattern.
 *
 * Tokens are `{YYYY}`, `{YY}`, `{FY}`, `{MM}`, `{DD}` and `{SEQ}`. Everything
 * outside a token is literal, which is how a prefix like `CI-` works without
 * needing a field of its own.
 *
 * **The year tokens follow the accounting year, not the calendar.** With a
 * 1 April start, an order placed on 10 February 2025 renders `{YYYY}` as 2024,
 * because it belongs to the year that began in April 2024. That is not a
 * nicety: with a yearly reset the sequence restarts in April, so a number
 * carrying the calendar year would repeat every January — March.
 *
 * `{FY}` writes the span the way an invoice usually does — `2024-25` — and
 * collapses to a plain year when the accounting year starts in January.
 */
readonly class OrderNumberFormat
{
    public const DEFAULT_PATTERN = 'CI-{YYYY}-{SEQ}';

    public const MIN_PADDING = 1;

    public const MAX_PADDING = 8;

    /** Every token the pattern understands. */
    public const TOKENS = ['YYYY', 'YY', 'FY', 'MM', 'DD', 'SEQ'];

    public function __construct(
        public string $pattern,
        public int $padding,
        public OrderNumberReset $reset,
        /** 1 for a calendar year, 4 for 1 April – 31 March. */
        public int $yearStartMonth = 1,
    ) {}

    public function render(int $sequence, ?Carbon $at = null): string
    {
        $at ??= now();
        $year = OrderNumberReset::accountingYear($at, $this->yearStartMonth);

        return str_replace(
            ['{YYYY}', '{YY}', '{FY}', '{MM}', '{DD}', '{SEQ}'],
            [
                (string) $year,
                substr((string) $year, -2),
                $this->accountingYearLabel($year),
                $at->format('m'),
                $at->format('d'),
                str_pad((string) $sequence, $this->padding, '0', STR_PAD_LEFT),
            ],
            $this->pattern,
        );
    }

    /**
     * "2024-25" for an April start, "2024" for a calendar year.
     *
     * Written out rather than left to the client to compose from {YYYY}, since
     * the second half is the *next* year and no token could give it.
     */
    public function accountingYearLabel(int $year): string
    {
        return $this->yearStartMonth === 1
            ? (string) $year
            : $year.'-'.substr((string) ($year + 1), -2);
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

        if ($this->yearStartMonth < 1 || $this->yearStartMonth > 12) {
            $problems[] = 'The accounting year has to start in a real month.';
        }

        foreach ($this->reset->requiredTokens() as $alternatives) {
            if ($this->hasAnyToken($alternatives)) {
                continue;
            }

            $problems[] = sprintf(
                'Restarting %s needs %s in the format, otherwise two orders in different periods would get the same number.',
                $this->reset === OrderNumberReset::Monthly ? 'every month' : 'every accounting year',
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
     * Three rather than one, because seeing the sequence advance is what makes
     * a wrong restart period obvious at a glance.
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
