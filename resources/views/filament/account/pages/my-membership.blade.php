<x-filament-panels::page>
    @php
        $subscription = $this->membership();
        $formats = \App\Enums\Format::cases();
    @endphp

    @if ($subscription === null)
        <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center">
            <p class="text-gray-500 dark:text-gray-400">You don't have a membership yet.</p>
            <a href="{{ route('membership.index') }}" class="mt-3 inline-block font-semibold text-primary-600 hover:underline">
                See membership plans
            </a>
        </div>
    @else
        <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ $subscription->plan?->name ?? 'Membership' }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $subscription->describeTermDates() }}</p>
                </div>
                <span class="text-xs font-semibold rounded-full px-3 py-1 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                    {{ $subscription->state()->label() }}
                </span>
            </div>

            <h4 class="mt-5 text-xs font-semibold uppercase tracking-wide text-gray-400">Your allowance</h4>
            <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($formats as $format)
                    @if ($subscription->allowanceFor($format) !== null)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-300">{{ $format->label() }}</span>
                            <strong class="text-gray-950 dark:text-white">{{ $subscription->describeRemaining($format) }}</strong>
                        </li>
                    @endif
                @endforeach
            </ul>

            <a href="{{ route('membership.manage') }}"
               class="mt-5 inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-500">
                Manage membership
            </a>
        </div>
    @endif
</x-filament-panels::page>
