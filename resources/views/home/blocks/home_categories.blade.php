{{-- "Webinar Categories", driven by its `home_categories` block (live industries). --}}
@php $categories = \App\Support\HomepageContent::industries($block); @endphp

@if ($categories)
    <x-home.categories
        :categories="$categories"
        :eyebrow="$block->value('eyebrow')"
        :heading="$block->value('heading')"
        :intro="$block->value('intro')"
    />
@endif
