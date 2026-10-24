{{--
    A single "Upload new" button that feeds a file straight into the library.
    The <input> carries the wire:model; the label is the visible button, so the
    whole control is one tap target. Loading state is driven off the file upload
    itself (wire:target="file"), covering both the browser upload and the server
    store that follows.
--}}
<div>
    <label
        class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-primary-500 focus-within:ring-2 focus-within:ring-primary-500 focus-within:ring-offset-2 dark:ring-offset-gray-900"
        wire:target="file"
    >
        <input type="file" wire:model="file" accept="image/*" class="sr-only">

        <span wire:loading.remove wire:target="file" class="inline-flex items-center gap-2">
            <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
            Upload new
        </span>

        <span wire:loading wire:target="file" class="inline-flex items-center gap-2">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
            </svg>
            Uploading…
        </span>
    </label>

    @error('file')
        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror
</div>
