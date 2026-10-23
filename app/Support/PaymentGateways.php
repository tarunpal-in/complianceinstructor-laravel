<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PaymentGateway;
use App\Models\Setting;
use App\Payments\PaymentGatewayManager;

/**
 * The checkout payment options, and which gateways are connected — §8 #1.
 *
 * Two layers, kept apart on purpose:
 *
 *  - A **gateway** (PayPal, Stripe) is what actually takes the money, and each
 *    has an admin on/off toggle (`payments.*_enabled`) plus a connection check
 *    (its driver's `isConfigured()`). Stripe ships off — a backup for the day
 *    PayPal has trouble — so customers normally see PayPal only.
 *  - A **checkout option** is what the buyer picks. PayPal backs two of them:
 *    "Credit or Debit Card" and "PayPal" both capture through the PayPal gateway
 *    (different PayPal funding sources — a hosted card form vs the wallet), so
 *    the client runs everything through PayPal while still offering a plain card
 *    option. Stripe, when switched on, adds its own card option.
 *
 * **Enabled** is the only thing the checkout form and its validation consult, so
 * turning a gateway off removes its option(s) everywhere at once. **Configured**
 * is an environment fact, checked when payment is actually taken
 * (PaymentController::start) — an order can be placed without live credentials;
 * the capture cannot.
 *
 * The `payments.*_enabled` keys appear here as literals (not built from the
 * enum) so SettingRegistry's "read somewhere" guard sees them.
 */
final class PaymentGateways
{
    /**
     * The options offered at checkout, in display order. Each names the gateway
     * that captures it and, for PayPal, the funding source the pay page renders.
     *
     * @var array<string, array{label: string, gateway: string, funding: ?string}>
     */
    private const OPTIONS = [
        'card' => ['label' => 'Credit or Debit Card', 'gateway' => 'paypal', 'funding' => 'card'],
        'paypal' => ['label' => 'PayPal', 'gateway' => 'paypal', 'funding' => 'paypal'],
        'stripe' => ['label' => 'Card (Stripe)', 'gateway' => 'stripe', 'funding' => null],
    ];

    /** The admin on/off setting for each gateway. */
    private const ENABLED_KEY = [
        'paypal' => 'payments.paypal_enabled',
        'stripe' => 'payments.stripe_enabled',
    ];

    /** Whether this gateway's admin toggle is on. */
    public static function isEnabled(PaymentGateway $gateway): bool
    {
        $key = self::ENABLED_KEY[$gateway->value] ?? null;

        return $key !== null && (bool) Setting::declared($key);
    }

    /** Whether the gateway's API credentials are present (its driver says so). */
    public static function isConfigured(PaymentGateway $gateway): bool
    {
        $manager = app(PaymentGatewayManager::class);

        return $manager->has($gateway) && $manager->driver($gateway)->isConfigured();
    }

    /** Enabled AND connected — usable to take a payment right now. */
    public static function isLive(PaymentGateway $gateway): bool
    {
        return self::isEnabled($gateway) && self::isConfigured($gateway);
    }

    /**
     * The options to offer at checkout, as option-key => label. An option shows
     * only when its backing gateway is switched on, so a configured-but-disabled
     * gateway is hidden; an enabled-but-unconfigured one still shows and fails
     * clearly at payment time (the behaviour before this existed).
     *
     * @return array<string, string>
     */
    public static function checkoutOptions(): array
    {
        $options = [];

        foreach (self::OPTIONS as $key => $meta) {
            if (self::isEnabled(PaymentGateway::from($meta['gateway']))) {
                $options[$key] = $meta['label'];
            }
        }

        return $options;
    }

    /**
     * The offered option keys, for the checkout validation rule.
     *
     * @return list<string>
     */
    public static function enabledValues(): array
    {
        return array_keys(self::checkoutOptions());
    }

    /** The option a checkout should default to — the first one offered, if any. */
    public static function defaultOption(): ?string
    {
        return array_key_first(self::checkoutOptions());
    }

    /** The gateway that captures a given checkout option. */
    public static function optionGateway(string $option): ?PaymentGateway
    {
        $value = self::OPTIONS[$option]['gateway'] ?? null;

        return $value !== null ? PaymentGateway::from($value) : null;
    }

    /** The PayPal funding source for a given option (null for non-PayPal). */
    public static function optionFunding(string $option): ?string
    {
        return self::OPTIONS[$option]['funding'] ?? null;
    }

    /** The gateway behind the default option — for a server-side fallback. */
    public static function defaultGateway(): ?PaymentGateway
    {
        $option = self::defaultOption();

        return $option !== null ? self::optionGateway($option) : null;
    }
}
