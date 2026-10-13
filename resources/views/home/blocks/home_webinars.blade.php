{{-- "Upcoming Webinars", driven by its `home_webinars` block (live catalogue).
     When nothing matches, the section is omitted rather than falling back to the
     comp placeholders — those are only for an unseeded install. --}}
@php $webinars = \App\Support\HomepageContent::webinars($block); @endphp

@if ($webinars)
    <x-home.webinars
        :webinars="$webinars"
        :eyebrow="$block->value('eyebrow')"
        :heading="$block->value('heading')"
        :intro="$block->value('intro')"
        :link-label="$block->value('link_label')"
        :link-url="$block->value('link_url')"
    />
@endif
