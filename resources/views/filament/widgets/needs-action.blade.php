@php($rows = $this->getRows())

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Waiting on you</x-slot>

        @if ($rows === [])
            <p class="fi-color-gray text-sm">Nothing is waiting. Everything invoiced has been paid, no payments have failed this week, and every inquiry has been picked up.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rows as $row)
                    <li class="flex items-center gap-3 py-2 text-sm">
                        <x-filament::badge :color="$row['tone']">{{ number_format($row['count']) }}</x-filament::badge>

                        @if ($row['url'])
                            <a href="{{ $row['url'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $row['label'] }}</a>
                        @else
                            <span>{{ $row['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
