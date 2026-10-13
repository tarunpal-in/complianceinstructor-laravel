@props([
    'categories' => null,
    'eyebrow' => null,
    'heading' => null,
    'intro' => null,
])

@php
    $eyebrow = $eyebrow ?: 'BROWSE BY INDUSTRY';
    $heading = $heading ?: 'Webinar Categories';
    $intro = $intro ?: 'Built around the regulations you actually answer to';
    $categories = $categories ?: [
        ['name' => 'Manufacturing', 'count' => 48, 'image' => 'laptop'],
        ['name' => 'Human Resource', 'count' => 48, 'image' => 'office'],
        ['name' => 'Microsoft Office', 'count' => 48, 'image' => 'seminar'],
    ];
@endphp

<section class="categories section" id="industry">
    <div class="section-heading centered">
        <div class="eyebrow">{{ $eyebrow }}</div>
        <h2>{{ $heading }}</h2>
        <p>{{ $intro }}</p>
    </div>

    <div class="category-grid">
        @foreach ($categories as $category)
            {{-- A real image URL if the record has one, else the comp's default
                 asset keyed by name. --}}
            @php $image = $category['image_url'] ?? asset('images/home/'.($category['image'] ?? 'seminar').'.jpg'); @endphp

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
