@props(['speakers' => []])

<section class="faculty section" id="speakers">
    <div class="faculty-copy">
        <div class="eyebrow">OUR INSTRUCTORS</div>
        <h2>Experienced Faculty</h2>
        <p>Our speakers have argued the cases, run the audits and built the programs. They bring the edge cases you won't find in a compliance handbook — and they stay on the line until your question is answered.</p>
        <button class="button primary" @click="showNotice('Meet the speakers')">Meet the Speakers</button>
    </div>

    {{-- The comp lifts cards 2 and 4 by 35px (see .faculty-card:nth-child). That
         stagger is dropped below 430px, where the grid is too tight for it. --}}
    <div class="faculty-cards">
        @foreach ($speakers as $speaker)
            <article class="faculty-card">
                <img src="{{ asset('images/home/'.$speaker['image'].'.jpg') }}" alt="{{ $speaker['name'] }}" width="940" height="628">
                <h3>{{ $speaker['name'] }}</h3>
                <p>{{ $speaker['credentials'] }}</p>
                <a href="#speakers">{{ $speaker['bodies'] }}</a>
            </article>
        @endforeach
    </div>
</section>
