<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PaymentGateway;
use App\Models\Setting;
use App\Settings\SettingGroup;
use App\Support\PaymentGateways as Gateways;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Payment gateways — CLAUDE.md §8 improvement #1.
 *
 * One switch per customer-facing gateway (PayPal, Stripe), each shown with its
 * live connection status. "Connection" is whether the gateway's API credentials
 * are present (its driver's own check); the toggle is whether it is offered at
 * checkout. The two are deliberately separate: a gateway can be connected and
 * switched off, or switched on while its keys are still missing — the status
 * line says which, and the checkout only offers a gateway whose toggle is on.
 *
 * The flag itself lives in the `payments` settings group; this is its only
 * screen (it is kept off the generic Settings tabs), and it is gated by the same
 * money permission as the rest of commerce.
 */
class PaymentGateways extends FilamentPage implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.payment-gateways';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|null|UnitEnum $navigationGroup = 'System';

    protected static ?string $title = 'Payment Gateways';

    protected static ?int $navigationSort = 20;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return SettingGroup::Payments->isEditableBy(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'Switch each payment method on or off for checkout, and see whether it is connected. A method must be switched on here to be offered to customers.';
    }

    public function mount(): void
    {
        $this->getForm('form')?->fill([
            'paypal_enabled' => Gateways::isEnabled(PaymentGateway::PayPal),
            'stripe_enabled' => Gateways::isEnabled(PaymentGateway::Stripe),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                $this->gatewaySection(
                    PaymentGateway::PayPal,
                    'paypal_enabled',
                    'Offer PayPal at checkout',
                    ['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET'],
                ),
                $this->gatewaySection(
                    PaymentGateway::Stripe,
                    'stripe_enabled',
                    'Offer Stripe (card) at checkout',
                    ['STRIPE_KEY', 'STRIPE_SECRET'],
                ),
            ]);
    }

    /** @param  array<int, string>  $envKeys */
    private function gatewaySection(PaymentGateway $gateway, string $field, string $toggleLabel, array $envKeys): Section
    {
        return Section::make($gateway->label())
            ->description('How this gateway is set up and whether customers can use it.')
            ->schema([
                Placeholder::make($gateway->value.'_status')
                    ->label('Connection')
                    ->content($this->statusHtml($gateway, $envKeys)),

                Toggle::make($field)
                    ->label($toggleLabel)
                    ->helperText('Turning this off removes the option from checkout immediately.'),
            ]);
    }

    /**
     * The connection status pill, derived from the driver's own credential check
     * plus the toggle, so the admin sees exactly where each gateway stands.
     *
     * @param  array<int, string>  $envKeys
     */
    private function statusHtml(PaymentGateway $gateway, array $envKeys): HtmlString
    {
        $configured = Gateways::isConfigured($gateway);
        $enabled = Gateways::isEnabled($gateway);

        [$dot, $text, $note] = match (true) {
            $configured && $enabled => ['#16a34a', 'Live at checkout', 'Connected and switched on.'],
            $configured => ['#9aa3af', 'Connected, switched off', 'Credentials are set; turn the switch below on to offer it.'],
            $enabled => ['#d97706', 'Switched on, not connected', 'Add '.implode(' and ', $envKeys).' to the environment to take payments.'],
            default => ['#9aa3af', 'Not connected', 'Add '.implode(' and ', $envKeys).' to the environment, then switch it on.'],
        };

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:8px;font-weight:600;">'
            .'<span style="width:10px;height:10px;border-radius:9999px;background:'.$dot.';display:inline-block;"></span>'
            .e($text).'</span>'
            .'<span style="display:block;color:#6b7280;font-size:0.8125rem;margin-top:4px;">'.e($note).'</span>'
        );
    }

    public function save(): void
    {
        // Defense in depth: canAccess already gates the page, but a form can be
        // rendered once and posted twice, so the permission is decided here too.
        // Skip rather than write when the actor may not — the same shape as the
        // Settings page, which saves only the groups its user owns.
        if (! SettingGroup::Payments->isEditableBy(auth()->user())) {
            return;
        }

        $state = $this->getForm('form')?->getState() ?? [];

        Setting::set('payments.paypal_enabled', (bool) ($state['paypal_enabled'] ?? false), 'boolean');
        Setting::set('payments.stripe_enabled', (bool) ($state['stripe_enabled'] ?? false), 'boolean');

        Notification::make()->title('Payment gateways updated')->success()->send();
    }
}
