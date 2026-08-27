@php
    $columns = [
        'Company' => ['About Us', 'Speakers', 'Blog', 'Careers', 'Press'],
        'Training' => ['Live Webinars', 'Recorded Webinars', 'Packages', 'Memberships'],
        'Resources' => ['FAQ', 'Forum', 'Quiz', 'Help Center', 'Contact'],
        'Legal' => ['Terms of Service', 'Privacy Policy', 'Refund Policy', 'Cookie Policy'],
    ];
@endphp

<footer class="relative overflow-hidden border-t border-cream-300 bg-cream-100">
    <div class="pointer-events-none absolute -bottom-8 -end-8 h-56 w-56 bg-dot-field text-navy-900/10" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-4 pb-8 pt-14 sm:px-6 lg:px-8 lg:pt-16">
        <div class="grid gap-10 lg:grid-cols-12 lg:gap-8">

            {{-- Brand + newsletter --}}
            <div class="lg:col-span-4">
                <a href="/" class="flex items-center gap-x-2.5" aria-label="{{ config('app.name') }} — home">
                    <span class="grid h-9 w-9 place-items-center rounded-lg bg-navy-900 text-gold-400" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 10v6M2 10l10-5 10 5-10 5z" /><path d="M6 12v5c3 3 9 3 12 0v-5" />
                        </svg>
                    </span>
                    <span class="text-[15px] font-bold tracking-tight">Compliance<span class="text-gold-600">Instructor</span></span>
                </a>

                <p class="mt-4 max-w-sm text-sm leading-relaxed text-navy-700">
                    Professional compliance training for HR, payroll, and operations teams across the United States.
                </p>

                <p class="mt-6 text-sm font-semibold text-navy-900">Stay informed — subscribe to our newsletter</p>

                {{-- Inert until P7 wires the subscribers table. --}}
                <form class="mt-3 flex max-w-sm gap-x-2" onsubmit="return false">
                    <label for="newsletter-email" class="sr-only">Email address</label>
                    <input
                        id="newsletter-email"
                        type="email"
                        autocomplete="email"
                        placeholder="your@email.com"
                        class="min-w-0 flex-1 rounded-full border border-cream-300 bg-white px-4 py-2.5 text-sm text-navy-900 placeholder:text-navy-300 focus:border-navy-400 focus:outline-2 focus:outline-offset-1 focus:outline-navy-400"
                    >
                    <button type="submit" class="shrink-0 rounded-full bg-gold-500 px-5 py-2.5 text-sm font-semibold text-navy-950 transition hover:bg-gold-400">
                        Subscribe
                    </button>
                </form>
            </div>

            {{-- Link columns --}}
            <div class="grid grid-cols-2 gap-8 sm:grid-cols-4 lg:col-span-8">
                @foreach ($columns as $heading => $links)
                    <div>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-navy-900">{{ $heading }}</h2>
                        <ul class="mt-4 space-y-2.5">
                            @foreach ($links as $link)
                                <li>
                                    <a href="#" class="text-sm text-navy-600 transition hover:text-navy-950 hover:underline">{{ $link }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Payment options --}}
        <div class="mt-12 border-t border-cream-300 pt-6">
            <h2 class="text-xs font-bold uppercase tracking-wider text-navy-900">Payment Options</h2>
            <ul class="mt-3 flex flex-wrap items-center gap-2.5">
                @foreach (['VISA', 'Mastercard', 'AMEX', 'PayPal'] as $method)
                    <li class="grid h-8 min-w-14 place-items-center rounded-md border border-cream-300 bg-white px-2.5 text-[10px] font-bold tracking-wide text-navy-700">
                        {{ $method }}
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="mt-8 flex flex-col-reverse items-center justify-between gap-4 border-t border-cream-300 pt-6 sm:flex-row">
            <p class="text-xs text-navy-600">
                &copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.
            </p>

            <ul class="flex items-center gap-x-2">
                @foreach ([
                    'Facebook' => 'M13.5 9H16l.5-3h-3V4.2c0-.9.3-1.4 1.5-1.4H16.5V.1C16.2.1 15.1 0 13.9 0 11.4 0 9.8 1.5 9.8 4v2H7v3h2.8v8h3.7V9Z',
                    'LinkedIn' => 'M4.98 3.5A2.5 2.5 0 1 1 0 3.5a2.5 2.5 0 0 1 4.98 0ZM.2 7.98h4.56V20H.2V7.98Zm7.4 0h4.37v1.64h.06c.6-1.1 2.08-2.26 4.28-2.26 4.58 0 5.43 2.9 5.43 6.68V20h-4.56v-5.3c0-1.27-.03-2.9-1.83-2.9-1.83 0-2.11 1.38-2.11 2.81V20H7.6V7.98Z',
                    'X' => 'M15.4 0h3l-6.6 7.5L19.6 20h-6.1l-4.8-6.2L3.2 20H.2l7-8L0 0h6.2l4.3 5.7L15.4 0Zm-1.1 18.2h1.7L5.8 1.7H4l10.3 16.5Z',
                ] as $name => $path)
                    <li>
                        <a href="#" aria-label="{{ $name }}" class="grid h-9 w-9 place-items-center rounded-full border border-cream-300 bg-white text-navy-700 transition hover:bg-navy-900 hover:text-white">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="{{ $path }}" /></svg>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</footer>
