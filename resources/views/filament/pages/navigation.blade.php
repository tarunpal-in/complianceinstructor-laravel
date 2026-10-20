<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">Save menus</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
