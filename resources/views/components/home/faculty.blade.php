@props(['speakers' => []])

@php
    // Two columns scrolling in opposite directions. The right-hand column is
    // rotated by two so the same face is never level with itself across the
    // gutter — with only four speakers, unrotated columns read as a mirror.
    $rotated = array_merge(array_slice($speakers, 2), array_slice($speakers, 0, 2));

    $columns = [
        ['direction' => 'down', 'speakers' => $speakers],
        ['direction' => 'up', 'speakers' => $rotated],
    ];
@endphp

<section class="faculty section" id="speakers">
    <div class="faculty-copy">
        <div class="eyebrow">OUR INSTRUCTORS</div>
        <h2>Experienced Faculty</h2>
        <p>Our speakers have argued the cases, run the audits and built the programs. They bring the edge cases you won't find in a compliance handbook — and they stay on the line until your question is answered.</p>
        <button class="button primary" @click="showNotice('Meet the speakers')">Meet the Speakers</button>
    </div>

    <div class="faculty-cards">
        @foreach ($columns as $column)
            <div class="faculty-column">
                <div class="faculty-track is-{{ $column['direction'] }}">
                    {{-- Each set is rendered twice. The keyframes translate by
                         exactly half the track height, so the second set lands
                         where the first began and the loop has no seam. The
                         duplicate is aria-hidden so a screen reader does not
                         read every speaker a second time. --}}
                    @foreach ([false, true] as $isDuplicate)
                        @foreach ($column['speakers'] as $speaker)
                            <article class="faculty-card" @if ($isDuplicate) aria-hidden="true" @endif>
                                <img
                                    src="{{ asset('images/home/'.$speaker['image'].'.jpg') }}"
                                    alt="{{ $isDuplicate ? '' : $speaker['name'] }}"
                                    width="940"
                                    height="628"
                                    loading="lazy"
                                >
                                <h3>{{ $speaker['name'] }}</h3>
                                <p>{{ $speaker['credentials'] }}</p>
                                <a href="#speakers" @if ($isDuplicate) tabindex="-1" @endif>{{ $speaker['bodies'] }}</a>
                            </article>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>
