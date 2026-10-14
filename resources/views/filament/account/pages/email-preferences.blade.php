<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Choose which optional emails you'd like to receive. Receipts, password resets and
        certificates are always sent and aren't listed here.
    </p>

    <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800 rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
        @foreach ($this->preferences() as $pref)
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <div>
                    <p class="font-medium text-gray-950 dark:text-white">{{ $pref['category']->label() }}</p>
                </div>
                <button type="button"
                        wire:click="toggle('{{ $pref['category']->value }}')"
                        role="switch"
                        aria-checked="{{ $pref['accepted'] ? 'true' : 'false' }}"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition {{ $pref['accepted'] ? 'bg-primary-600' : 'bg-gray-300 dark:bg-gray-700' }}">
                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition {{ $pref['accepted'] ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    <span class="sr-only">Toggle {{ $pref['category']->label() }}</span>
                </button>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
