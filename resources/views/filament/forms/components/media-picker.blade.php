{{--
    A visual library picker: a thumbnail of the current choice plus a "Choose"
    button that opens a modal grid of the library's images to pick from. The
    chosen media id is held in the field's state (entangled), so saving the form
    persists it like any other field. Images are escaped where named; the thumb
    URLs come from the media library.
--}}
@php
    $statePath = $getStatePath();
    $items = $getLibraryItems();
    $selected = $getSelectedItem();
    // One event name per picker (its state path), so a screen with two pickers
    // only lets the one whose uploader ran receive the new file.
    $uploadEvent = 'library-uploaded-'.\Illuminate\Support\Str::slug($statePath);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            open: false,
            search: '',
            state: $wire.$entangle('{{ $statePath }}'),
            items: {{ \Illuminate\Support\Js::from($items) }},
            fallback: {{ \Illuminate\Support\Js::from($selected) }},
            get current() {
                if (! this.state) { return null; }
                return this.items.find((i) => i.id == this.state) ?? this.fallback;
            },
            get filtered() {
                if (! this.search) { return this.items; }
                const needle = this.search.toLowerCase();
                return this.items.filter((i) => i.name.toLowerCase().includes(needle));
            },
            pick(id) { this.state = id; this.open = false; },
            clear() { this.state = null; },
            onUploaded(item) {
                if (! this.items.some((i) => i.id == item.id)) { this.items.unshift(item); }
                this.fallback = item;
                this.pick(item.id);
            },
        }"
        x-on:{{ $uploadEvent }}.window="onUploaded($event.detail.item)"
    >
        {{-- Preview + trigger --}}
        <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-white/5">
            <div class="flex h-14 w-14 flex-none items-center justify-center overflow-hidden rounded-md border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                <template x-if="current && current.thumb">
                    <img :src="current.thumb" alt="" class="h-full w-full object-contain p-1">
                </template>
                <template x-if="! current">
                    <x-filament::icon icon="heroicon-o-photo" class="h-6 w-6 text-gray-300 dark:text-gray-600" />
                </template>
            </div>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-gray-950 dark:text-white" x-text="current ? current.name : 'Nothing selected'"></p>
                <p class="text-xs text-gray-500">Picked from the Media library</p>
            </div>

            <div class="flex flex-none gap-2">
                <x-filament::button size="sm" icon="heroicon-m-photo" type="button" x-on:click="open = true">
                    Choose
                </x-filament::button>
                <x-filament::button size="sm" color="gray" type="button" x-show="state" x-cloak x-on:click="clear()">
                    Remove
                </x-filament::button>
            </div>
        </div>

        {{-- Modal: the library grid --}}
        <template x-teleport="body">
            <div x-show="open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center p-4"
                 x-on:keydown.escape.window="open = false">
                <div class="absolute inset-0 bg-gray-950/50" x-on:click="open = false"></div>

                <div class="relative z-10 flex max-h-[85vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                     x-transition.opacity>
                    <div class="flex items-center gap-3 border-b border-gray-200 p-4 dark:border-white/10">
                        <h2 class="flex-1 text-base font-semibold text-gray-950 dark:text-white">Media library</h2>
                        <livewire:library-quick-upload :channel="$uploadEvent" :key="'upload-'.$uploadEvent" />
                        <input type="search" x-model="search" placeholder="Search by name…"
                               class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                        <button type="button" x-on:click="open = false"
                                class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-200">
                            <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="grid grid-cols-3 gap-3 overflow-y-auto p-4 sm:grid-cols-4 md:grid-cols-5">
                        <template x-for="item in filtered" :key="item.id">
                            <button type="button" x-on:click="pick(item.id)"
                                    class="group flex flex-col gap-1.5 rounded-lg border p-2 text-left transition hover:border-primary-500"
                                    :class="state == item.id ? 'border-primary-500 ring-2 ring-primary-500' : 'border-gray-200 dark:border-white/10'">
                                <div class="flex aspect-square items-center justify-center overflow-hidden rounded-md bg-gray-50 dark:bg-white/5">
                                    <img :src="item.thumb" alt="" loading="lazy" class="h-full w-full object-contain p-1">
                                </div>
                                <span class="truncate text-xs text-gray-700 dark:text-gray-300" x-text="item.name"></span>
                            </button>
                        </template>

                        <p x-show="filtered.length === 0" class="col-span-full py-10 text-center text-sm text-gray-500">
                            No matching images. Use “Upload new” above to add one.
                        </p>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 p-3 dark:border-white/10">
                        <x-filament::button color="gray" type="button" x-on:click="open = false">Close</x-filament::button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</x-dynamic-component>
