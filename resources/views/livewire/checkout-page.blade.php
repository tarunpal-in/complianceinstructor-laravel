@php use App\Support\Money; @endphp

<div class="checkout">
    <form wire:submit="place" class="checkout-grid">
        <div class="checkout-main">
            {{-- Your details --}}
            <div class="co-card">
                <h2>Your details</h2>
                <div class="co-fields">
                    <label class="co-field">
                        <span>Full name</span>
                        <input type="text" wire:model="name" autocomplete="name">
                        @error('name') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Email</span>
                        <input type="email" wire:model="email" autocomplete="email">
                        @error('email') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Job title</span>
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
                        <span>Address</span>
                        <input type="text" wire:model="addressLine1" autocomplete="address-line1">
                        @error('addressLine1') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field co-field-full">
                        <span>Address line 2 <small>(optional)</small></span>
                        <input type="text" wire:model="addressLine2" autocomplete="address-line2">
                        @error('addressLine2') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>City</span>
                        <input type="text" wire:model="city" autocomplete="address-level2">
                        @error('city') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>State / Region <small>(optional)</small></span>
                        <input type="text" wire:model="region" autocomplete="address-level1">
                        @error('region') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Postal code</span>
                        <input type="text" wire:model="postalCode" autocomplete="postal-code">
                        @error('postalCode') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                    <label class="co-field">
                        <span>Country</span>
                        <select wire:model="country" autocomplete="country-name">
                            @foreach (\App\Support\Countries::names() as $countryName)
                                <option value="{{ $countryName }}">{{ $countryName }}</option>
                            @endforeach
                        </select>
                        @error('country') <em class="co-err">{{ $message }}</em> @enderror
                    </label>
                </div>
            </div>

            {{-- Attendees --}}
            <div class="co-card">
                <h2>Who is attending?</h2>
                <p class="co-note">A certificate is issued to each named attendee. Seats can differ from the buyer.</p>
                @foreach ($attendees as $i => $attendee)
                    <div class="co-attendee" wire:key="seat-{{ $i }}">
                        <span class="co-seat-no">Seat {{ $i + 1 }}@if ($i === 0) <small>(you)</small>@endif</span>
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
            </div>

            {{-- Payment method --}}
            <div class="co-card">
                <h2>Payment method</h2>
                <div class="co-gateways">
                    <label class="co-gateway">
                        <input type="radio" wire:model="gateway" value="paypal">
                        <span>PayPal</span>
                    </label>
                    <label class="co-gateway">
                        <input type="radio" wire:model="gateway" value="stripe">
                        <span>Card (Stripe)</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Summary --}}
        <aside class="checkout-summary">
            <h2>Order summary</h2>

            <ul class="co-lines">
                @foreach ($cart->items as $item)
                    <li wire:key="line-{{ $item->id }}">
                        <div>
                            <strong>{{ $item->offering?->offerable?->title ?? 'Item' }}</strong>
                            <span>{{ $item->offering?->label() }} × {{ $item->quantity }}</span>
                        </div>
                        <span>{{ Money::format($item->lineSubtotalCents(), $cart->currency) }}</span>
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
