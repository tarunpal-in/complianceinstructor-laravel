@props(['sections'])

@php
    // Speaker(s) is a tab but not a text section, so it is appended here rather
    // than faked into contentSections().
    $tabs = array_merge($sections, [['id' => 'speakers', 'heading' => 'Speaker(s)']]);
@endphp

{{-- Full-bleed, and an anchor list rather than tab widgets: these scroll the
     page rather than swapping panels, so role="tablist" would promise keyboard
     behaviour that does not exist and is not wanted. --}}
<nav class="wd-tabs" aria-label="Sections of this page" x-data="webinarTabs">
    @foreach ($tabs as $tab)
        <a
            href="#{{ $tab['id'] }}"
            x-bind:class="active === '{{ $tab['id'] }}' ? 'is-active' : ''"
            @class(['is-active' => $loop->first])
        >{{ $tab['heading'] }}</a>
    @endforeach
</nav>
