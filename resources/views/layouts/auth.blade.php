<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="auth-body">
    <main class="auth-shell">
        {{-- Brand panel --}}
        <aside class="auth-brand">
            <a href="{{ route('home') }}" class="auth-brand-mark">
                <x-ui.icon name="shield-check" :size="28" />
                <span>COMPLIANCE<small>INSTRUCTOR</small></span>
            </a>
            <div class="auth-brand-copy">
                <h2>Continuing education that keeps your credentials current.</h2>
                <p>HRCI, SHRM, CPE and APA credits from working practitioners — live, recorded, and on your schedule.</p>
            </div>
            <p class="auth-brand-foot">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        </aside>

        {{-- Form --}}
        <section class="auth-main">
            {{ $slot }}
        </section>
    </main>
    @livewireScripts
</body>
</html>
