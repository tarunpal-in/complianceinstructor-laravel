<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Cart\CartManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an add-to-cart post — CLAUDE.md §4 rule 3 (Form Requests for all
 * input). The purchase panel posts a `quantities` map of offering id → count.
 *
 * Note what is NOT accepted: any price, total or discount. The cart reads the
 * price from the offering (§14 #1), so the request carries only *what* and *how
 * many*, never *for how much*.
 */
class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:0', 'max:'.CartManager::MAX_QUANTITY],
        ];
    }

    /**
     * The offering id → quantity pairs with a positive quantity.
     *
     * @return array<int, int>
     */
    public function selectedQuantities(): array
    {
        /** @var array<int|string, mixed> $quantities */
        $quantities = $this->validated('quantities');

        $selected = [];

        foreach ($quantities as $offeringId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity > 0) {
                $selected[(int) $offeringId] = $quantity;
            }
        }

        return $selected;
    }
}
