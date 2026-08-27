@props([
    {{-- CLAUDE.md §14 #3 bans serialising the coupon table into a page. One
         promoted code is marketing, not validation — but it still belongs in
         `settings` (§5.6) rather than hardcoded here, which is a P6 change. --}}
    'code' => 'TRAINING',
    'amount' => '$10',
])

<div class="bg-navy-900 text-white">
    <div class="mx-auto flex max-w-7xl items-center justify-center gap-x-2 px-4 py-2.5 text-center sm:px-6 lg:px-8">
        <p class="text-xs sm:text-sm">
            Purchase any webinar and use code
            <span class="mx-0.5 rounded bg-white/15 px-1.5 py-0.5 font-semibold tracking-wide text-gold-300">{{ $code }}</span>
            at checkout to save {{ $amount }}.
        </p>
    </div>
</div>
