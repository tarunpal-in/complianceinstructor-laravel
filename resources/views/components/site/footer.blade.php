@php
    $columns = [
        'Company' => ['About Us', 'Speakers', 'Blog', 'Careers', 'Press'],
        'Training' => ['Live Webinars', 'Recorded Webinars', 'Packages', 'Memberships'],
        'Resources' => ['FAQ', 'Forum', 'Quiz', 'Help Center', 'Contact'],
        'Legal' => ['Terms of Service', 'Privacy Policy', 'Refund Policy', 'Cookie Policy'],
    ];
@endphp

<footer class="footer">
    <div class="footer-grid">
        <div class="footer-intro">
            <x-site.brand />

            <p>Professional compliance training for HR, payroll, and operations teams across the United States.</p>

            <strong>Stay informed — subscribe to our newsletter</strong>

            {{-- Inert until P7 wires the subscribers table. --}}
            <form @submit.prevent="showNotice($refs.email.value ? 'You are subscribed' : 'Enter your email address'); $refs.email.value = ''">
                <label for="newsletter-email" class="sr-only">Email address</label>
                <input id="newsletter-email" x-ref="email" type="email" autocomplete="email" placeholder="your@email.com">
                <button type="submit">Subscribe</button>
            </form>

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
