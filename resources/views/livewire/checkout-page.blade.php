@php use App\Support\Money; @endphp

<div class="checkout">
    <form wire:submit="place" class="checkout-grid">
        <div class="checkout-main">
            {{-- Your details --}}
            <div class="co-card">
                <h2>Your details</h2>
                <div class="co-fields">
                    <label class="co-field">
                        <span>Full name <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="text" wire:model="name" autocomplete="name">
                        @error('name') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Email <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="email" wire:model="email" autocomplete="email">
                        @error('email') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Phone <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="tel" wire:model="phone" autocomplete="tel">
                        @error('phone') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Job title <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="text" wire:model="jobTitle" autocomplete="organization-title">
                        @error('jobTitle') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Company <small>(optional)</small></span>
                        <input type="text" wire:model="company" autocomplete="organization">
                        @error('company') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                </div>
            </div>

            {{-- Billing address --}}
            <div class="co-card">
                <h2>Billing address</h2>
                <div class="co-fields">
                    <label class="co-field co-field-full">
                        <span>Address <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="text" wire:model="addressLine1" autocomplete="address-line1">
                        @error('addressLine1') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field co-field-full">
                        <span>Address line 2 <small>(optional)</small></span>
                        <input type="text" wire:model="addressLine2" autocomplete="address-line2">
                        @error('addressLine2') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>City <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="text" wire:model="city" autocomplete="address-level2">
                        @error('city') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>State / Region <small>(optional)</small></span>
                        <input type="text" wire:model="region" autocomplete="address-level1">
                        @error('region') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Postal code <span class="co-req" aria-hidden="true">*</span></span>
                        <input type="text" wire:model="postalCode" autocomplete="postal-code">
                        @error('postalCode') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Country <span class="co-req" aria-hidden="true">*</span></span>
                        <select wire:model="country" autocomplete="country-name">
                            @foreach (\App\Support\Countries::names() as $countryName)
                                <option value="{{ $countryName }}">{{ $countryName }}</option>
                            @endforeach
                        </select>
                        @error('country') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                </div>
            </div>

            {{-- Attendees — just the buyer by default; more are added on demand, up
                 to the number of seats bought. Any seat left unnamed is registered
                 to the buyer (PlaceOrder). --}}
            <div class="co-card">
                <h2>Who is attending?</h2>
                <p class="co-note">A certificate is issued to each named attendee. Any seat you don't name is registered to you.</p>

                @foreach ($attendees as $i => $attendee)
                    <div class="co-attendee" wire:key="seat-{{ $i }}">
                        <div class="co-attendee-head">
                            <span class="co-seat-no">Seat {{ $i + 1 }}@if ($i === 0) <small>(you)</small>@endif</span>
                            @if ($i > 0)
                                <button type="button" class="co-seat-remove" wire:click="removeAttendee({{ $i }})">Remove</button>
                            @endif
                        </div>
                        <div class="co-fields">
                            <label class="co-field">
                                <span class="sr-only">Attendee {{ $i + 1 }} name</span>
                                <input type="text" wire:model="attendees.{{ $i }}.name" placeholder="Name">
                                @error("attendees.{$i}.name") <em class="co-err">{{ $message }}</em> @enderror
                            </label>
                            <label class="co-field">
                                <span class="sr-only">Attendee {{ $i + 1 }} email</span>
                                <input type="email" wire:model="attendees.{{ $i }}.email" placeholder="Email">
                                @error("attendees.{$i}.email") <em class="co-err">{{ $message }}</em> @enderror
                            </label>
                        </div>
                    </div>
                @endforeach

                @php($seatsLeft = $maxAttendees - count($attendees))
                @if ($seatsLeft > 0)
                    <button type="button" class="co-add-attendee" wire:click="addAttendee">
                        + Add attendee <small>({{ $seatsLeft }} seat{{ $seatsLeft === 1 ? '' : 's' }} left)</small>
                    </button>
                @endif
            </div>
        </div>

        {{-- Summary --}}
        <aside class="checkout-summary">
            <h2>Order summary</h2>

            <ul class="co-lines">
                @foreach ($cart->items as $item)
                    @php($lineWebinar = $item->offering?->offerable instanceof \App\Models\Webinar ? $item->offering->offerable : null)
                    @php($lineImage = $lineWebinar?->cardImageUrl())
                    <li wire:key="line-{{ $item->id }}">
                        <div class="co-line-thumb">
                            @if ($lineImage)
                                <img src="{{ $lineImage }}" alt="" loading="lazy">
                            @else
                                <span class="co-line-thumb-none" aria-hidden="true"></span>
                            @endif
                        </div>
                        <div class="co-line-info">
                            <strong>{{ $item->offering?->offerable?->title ?? 'Item' }}</strong>
                            <span>{{ $item->offering?->label() }} × {{ $item->quantity }}</span>
                        </div>
                        <span class="co-line-price">{{ Money::format($item->lineSubtotalCents(), $cart->currency) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="co-coupon">
                @if ($appliedCoupon)
                    <div class="co-coupon-applied">
                        <span><strong>{{ $appliedCoupon }}</strong> applied</span>
                        <button type="button" wire:click="removeCoupon">Remove</button>
                    </div>
                @else
                    <div class="co-coupon-form">
                        <input type="text" wire:model="couponCode" placeholder="Coupon code" aria-label="Coupon code">
                        <button type="button" wire:click="applyCoupon">Apply</button>
                    </div>
                    @if ($couponError) <em class="co-err">{{ $couponError }}</em> @endif
                @endif
            </div>

            <dl class="co-totals">
                <div><dt>Subtotal</dt><dd>{{ Money::format($subtotal, $cart->currency) }}</dd></div>
                @if ($discount > 0)
                    <div class="co-discount"><dt>Discount</dt><dd>−{{ Money::format($discount, $cart->currency) }}</dd></div>
                @endif
                <div><dt>Tax</dt><dd>{{ Money::format($tax, $cart->currency) }}</dd></div>
                <div class="co-total"><dt>Total</dt><dd>{{ Money::format($total, $cart->currency) }}</dd></div>
            </dl>

            {{-- Payment method — right by Place order, only the options whose
                 gateway is switched on. --}}
            @php($paymentOptions = \App\Support\PaymentGateways::checkoutOptions())
            <div class="co-pay">
                <h3 class="co-pay-title">Payment method</h3>

                @if (empty($paymentOptions))
                    <p class="co-gateways-none">No payment method is available right now. Please contact us to complete your order.</p>
                @else
                    @php($payDesc = ['card' => 'Visa, Mastercard, Amex & Discover', 'paypal' => 'Pay with your PayPal account', 'stripe' => 'Secure card payment'])
                    <div class="co-pay-options">
                        @foreach ($paymentOptions as $value => $label)
                            <label class="co-pay-option">
                                <input type="radio" wire:model="gateway" value="{{ $value }}">
                                <span class="co-pay-ico">
                                    @if ($value === 'paypal')
                                        <span class="co-pp"><b>Pay</b>Pal</span>
                                    @else
                                        <x-ui.icon name="credit-card" :size="22" />
                                    @endif
                                </span>
                                <span class="co-pay-text">
                                    <strong>{{ $label }}</strong>
                                    <small>{{ $payDesc[$value] ?? '' }}</small>
                                </span>
                                <span class="co-pay-dot" aria-hidden="true"></span>
                            </label>
                        @endforeach
                    </div>

                    @if (array_key_exists('card', $paymentOptions))
                        <div class="co-pay-brands" aria-hidden="true">
                            <span>VISA</span><span>Mastercard</span><span>AMEX</span><span>Discover</span>
                        </div>
                    @endif

                    @error('gateway') <em class="co-err">{{ $message }}</em> @enderror
                @endif

                <p class="co-pay-secure"><x-ui.icon name="lock" :size="13" /> Encrypted &amp; processed securely. Cards handled by our payment partner.</p>
            </div>

            @if ($placeError) <p class="co-err co-place-err">{{ $placeError }}</p> @endif

            <button type="submit" class="btn-primary co-place" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="place">Place order</span>
                <span wire:loading wire:target="place">Placing…</span>
            </button>

            <p class="co-trust">
                <x-ui.icon name="shield-check" :size="14" /> Secure checkout · 7-day refund
            </p>
        </aside>
    </form>
</div>
