@php
    $business = \App\Support\Business::class;
    $socials = $business::socials();

    // The footer menu from the navigation manager (§5.6), falling back to the
    // designed columns until the client edits it.
    $columns = \App\Support\Navigation::footer();
@endphp

<footer class="footer">
    <div class="footer-grid">
        <div class="footer-intro">
            <x-site.brand />

            @if ($tagline = $business::tagline())
                <p>{{ $tagline }}</p>
            @endif

            <strong>Stay informed — subscribe to our newsletter</strong>

            @if (session('mailing_list_status'))
                <p class="footer-newsletter-notice" role="status">{{ session('mailing_list_status') }}</p>
            @endif

            <form method="POST" action="{{ route('newsletter.store') }}">
                @csrf
                <x-recaptcha-token action="newsletter" />

                {{-- The honeypot. Hidden from people, filled in by scripts
                     walking the DOM — same trap as the comment form. --}}
                <div class="comment-trap" aria-hidden="true">
                    <label for="footer-company-website">Leave this empty</label>
                    <input id="footer-company-website" type="text"
                           name="{{ \App\Http\Requests\StoreSubscriberRequest::TRAP_FIELD }}"
                           tabindex="-1" autocomplete="off">
                </div>

                <label for="newsletter-email" class="sr-only">Email address</label>
                <input id="newsletter-email" type="email" name="email" required
                       autocomplete="email" placeholder="your@email.com" value="{{ old('email') }}">

                {{-- Subscribe and Unsubscribe share one email field. The second
                     button posts the same form to the unsubscribe-request route
                     via formaction — which emails a signed opt-out link rather
                     than removing an address on sight, so nobody can take anybody
                     else off the list by typing their address (§9). --}}
                <div class="footer-newsletter-actions">
                    <button type="submit">Subscribe</button>
                    <button type="submit"
                            class="footer-newsletter-unsubscribe"
                            formaction="{{ route('mailing-list.unsubscribe.request') }}">Unsubscribe</button>
                </div>
            </form>

            @error('email')
                <p class="footer-newsletter-error" role="alert">{{ $message }}</p>
            @enderror

            {{-- Only the accounts that have a URL. All three used to link to
                 `#top`, which is worse than not showing them — a visitor
                 clicks and nothing happens. --}}
            @if ($socials !== [])
                <div class="socials">
                    @foreach ($socials as $network => $url)
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                           aria-label="{{ ucfirst($network) }}"><x-ui.icon :name="$network" :size="18" /></a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="footer-links">
            @foreach ($columns as $column)
                <div>
                    <h4>{{ $column['label'] }}</h4>
                    @foreach ($column['children'] as $link)
                        <a href="{{ $link['url'] ?: '#' }}" @if ($link['new_tab']) target="_blank" rel="noopener" @endif>{{ $link['label'] }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    @if ($business::hasContactDetails())
        <div class="footer-contact">
            @foreach ($business::addressLines() as $line)
                <span>{{ $line }}</span>
            @endforeach

            @if ($email = $business::email())
                <a href="mailto:{{ $email }}">{{ $email }}</a>
            @endif

            @if ($phone = $business::phone())
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a>
            @endif
        </div>
    @endif

    <div class="payments">
        <strong>PAYMENTS OPTIONS</strong>
        <div><b>VISA</b><b>AMEX</b><b>●●</b><b>PayPal</b></div>
    </div>

    <div class="copyright">&copy; {{ now()->year }} {{ $business::legalName() }}. All rights reserved.</div>
</footer>
