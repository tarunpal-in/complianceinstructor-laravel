@php
    /** @var \App\Models\Media $media */
    $media = $getRecord();
    $kind = $media->kind();
    $image = $media->detailImageUrl();
    $isPdf = $kind === \App\Enums\MediaKind::Pdf;
@endphp

<div class="space-y-2">
    @if ($image)
        <div class="flex items-center justify-center rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
            <img
                src="{{ $image }}"
                alt="{{ $media->alt !== '' ? $media->alt : $media->name }}"
                class="max-h-80 w-auto object-contain"
            >
        </div>
    @elseif ($isPdf)
        {{-- Browsers render PDFs natively, so confirming this is the right file --}}
        {{-- costs nothing and saves a download. A browser that cannot show one --}}
        {{-- falls through to the link inside the object element. --}}
        <object
            data="{{ $media->getUrl() }}#toolbar=0&navpanes=0"
            type="application/pdf"
            class="h-96 w-full rounded-lg border border-gray-200 dark:border-white/10"
        >
            <div class="p-4 text-sm text-gray-500 dark:text-gray-400">
                This browser cannot show PDFs inline.
                <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener" class="font-medium underline">
                    Open it in a new tab
                </a>.
            </div>
        </object>
    @else
        <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
            <x-filament::icon :icon="$kind->icon()" class="h-8 w-8 text-gray-400" />
            <div class="text-sm">
                <p class="font-medium text-gray-950 dark:text-white">{{ $kind->label() }}</p>
                <p class="text-gray-500 dark:text-gray-400">
                    No preview is possible for this format — download it to open it.
                </p>
            </div>
        </div>
    @endif
</div>
