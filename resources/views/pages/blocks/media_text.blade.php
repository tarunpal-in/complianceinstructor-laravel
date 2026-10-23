{{-- Prose beside a picture. With no image, it reads as full-width text. --}}
@php
    $imagePath = $block->value('image');
    $imageUrl = filled($imagePath) ? \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath) : null;
    $position = $block->value('image_position') === 'left' ? 'left' : 'right';
@endphp
<section class="section pb-block pb-media-text {{ $imageUrl ? 'has-image image-'.$position : '' }}">
    <div class="pb-media-text-body">
        @if ($heading = $block->value('heading'))
            <h2 class="pb-heading">{{ $heading }}</h2>
        @endif
        <div class="pb-prose">{!! \App\Support\Html::sanitize($block->value('body')) !!}</div>
    </div>

    @if ($imageUrl)
        <div class="pb-media-text-image">
            <img src="{{ $imageUrl }}" alt="{{ $block->value('image_alt') }}" loading="lazy">
        </div>
    @endif
</section>
