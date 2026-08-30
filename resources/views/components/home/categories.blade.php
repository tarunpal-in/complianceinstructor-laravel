@props(['categories' => []])

<section class="categories section" id="industry">
    <div class="section-heading centered">
        <div class="eyebrow">BROWSE BY INDUSTRY</div>
        <h2>Webinar Categories</h2>
        <p>Built around the regulations you actually answer to</p>
    </div>

    <div class="category-grid">
        @foreach ($categories as $category)
            @php $image = asset('images/home/'.$category['image'].'.jpg'); @endphp

            {{-- The scrim is part of the background shorthand in the comp; it is
                 what carries the white heading to AA contrast over photography. --}}
            <article
                class="category-card"
                style="background-image: linear-gradient(0deg, rgba(5,39,72,.9), rgba(9,45,82,.05)), url('{{ $image }}')"
            >
                <div>
                    <small>{{ $category['count'] }} Courses</small>
                    <h3>{{ $category['name'] }}</h3>
                    <button @click="showNotice('{{ $category['name'] }} courses opened')">View Courses</button>
                </div>
            </article>
        @endforeach
    </div>
</section>
