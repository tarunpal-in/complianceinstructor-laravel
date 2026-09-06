@php
    /** @var \App\Models\Media $media */
    $media = $getRecord();
    $kind = $media->kind();
    $thumbnail = $media->thumbnailUrl();
@endphp

<div class="group flex flex-col gap-2">
    <div
        @class([
            'relative flex aspect-square items-center justify-center overflow-hidden rounded-lg',
            'border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5',
        ])
    >
        @if ($thumbnail)
            {{-- object-contain, never cover: a logo cropped to a square in the --}}
            {{-- grid is unrecognisable, which defeats the point of a thumbnail. --}}
            <img
                src="{{ $thumbnail }}"
                alt="{{ $media->alt !== '' ? $media->alt : $media->name }}"
                loading="lazy"
                class="h-full w-full object-contain p-2"
            >
        @else
            <div class="flex flex-col items-center gap-1.5 text-gray-400 dark:text-gray-500">
                <x-filament::icon :icon="$kind->icon()" class="h-8 w-8" />
                <span class="text-[10px] font-semibold uppercase tracking-wide">
                    {{ pathinfo($media->file_name, PATHINFO_EXTENSION) }}
                </span>
            </div>
        @endif

        {{-- An image with no alt text is a WCAG AA failure (§7 P9). Flagging it --}}
        {{-- on the card is what turns the audit into a worklist you can see. --}}
        @if ($media->needsAltText())
            <span
                class="absolute left-1.5 top-1.5 rounded bg-amber-500/90 px-1.5 py-0.5 text-[10px] font-semibold text-white"
                title="This image has no alt text"
            >
                No alt
            </span>
        @endif

        @if (! $media->isLibraryItem())
            <span
                class="absolute right-1.5 top-1.5 rounded bg-gray-900/70 px-1.5 py-0.5 text-[10px] font-medium text-white"
                title="Attached to {{ class_basename($media->model_type) }} — managed on that record"
            >
                Attached
            </span>
        @endif
    </div>

    <div class="min-w-0">
        <p class="truncate text-sm font-medium text-gray-950 dark:text-white" title="{{ $media->name }}">
            {{ $media->name }}
        </p>
        <p class="truncate text-xs text-gray-500 dark:text-gray-400">
            {{ $kind->label() }} · {{ $media->readableSize() }}@if ($media->document?->page_count) · {{ $media->document->page_count }}p @endif
        </p>
    </div>
</div>
