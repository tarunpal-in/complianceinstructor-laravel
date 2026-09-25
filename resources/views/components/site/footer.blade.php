@php
    $columns = [
        'Company' => ['About Us', 'Speakers', 'Blog', 'Careers', 'Press'],
        'Training' => ['Live Webinars', 'Recorded Webinars', 'Packages', 'Memberships'],
        'Resources' => ['FAQ', 'Help Center', 'Contact'],
        'Legal' => ['Terms of Service', 'Privacy Policy', 'Refund Policy', 'Cookie Policy'],
    ];
@endphp

<footer class="footer">
    <div class="footer-grid">
        <div class="footer-intro">
            <x-site.brand />

            <p>Professional compliance training for HR, payroll, and operations teams across the United States.</p>

            <strong>Stay informed — subscribe to our newsletter</strong>

            @if (session('mailing_list_status'))
                <p class="footer-newsletter-notice" role="status">{{ session('mailing_list_status') }}</p>
            @endif

            <form method="POST" action="{{ route('newsletter.store') }}">
                @csrf

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
                <button type="submit">Subscribe</button>
            </form>

            @error('email')
                <p class="footer-newsletter-error" role="alert">{{ $message }}</p>
            @enderror

            <div class="socials">
                <a href="#top" aria-label="LinkedIn"><x-ui.icon name="linkedin" :size="18" /></a>
                <a href="#top" aria-label="Instagram"><x-ui.icon name="instagram" :size="18" /></a>
                <a href="#top" aria-label="YouTube"><x-ui.icon name="youtube" :size="18" /></a>
            </div>
        </div>

        <div class="footer-links">
            @foreach ($columns as $heading => $links)
                <div>
                    <h4>{{ $heading }}</h4>
                    @foreach ($links as $link)
                        <a href="#top">{{ $link }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <div class="payments">
        <strong>PAYMENTS OPTIONS</strong>
        <div><b>VISA</b><b>AMEX</b><b>●●</b><b>PayPal</b></div>
    </div>

    <div class="copyright">&copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.</div>
</footer>
