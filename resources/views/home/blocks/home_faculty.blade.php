{{-- "Experienced Faculty", driven by its `home_faculty` block (live speakers). --}}
@php $speakers = \App\Support\HomepageContent::speakers($block); @endphp

@if ($speakers)
    <x-home.faculty
        :speakers="$speakers"
        :eyebrow="$block->value('eyebrow')"
        :heading="$block->value('heading')"
        :intro="$block->value('intro')"
        :button-label="$block->value('button_label')"
        :button-url="$block->value('button_url')"
    />
@endif
