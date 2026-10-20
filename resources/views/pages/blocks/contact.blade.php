{{-- Contact — editable copy and detail cards (the `contact` block) beside the
     Livewire contact form. Everything here is admin-editable; the form records
     an inquiry through RecordInquiry. --}}
<section class="section pb-block pb-contact" id="contact">
    @if (($heading = $block->value('heading')) || ($intro = $block->value('intro')))
        <div class="section-heading centered">
            @if ($heading)<h2>{{ $heading }}</h2>@endif
            @if ($intro = $block->value('intro'))<p>{{ $intro }}</p>@endif
        </div>
    @endif

    <div class="pb-contact-grid">
        <aside class="pb-contact-info">
            @foreach ($block->rows('details') as $detail)
                <div class="pb-contact-card">
                    <span class="pb-contact-icon"><x-ui.icon :name="$detail['icon'] ?? 'mail'" :size="20" /></span>
                    <div class="pb-contact-card-body">
                        <strong>{{ $detail['label'] ?? '' }}</strong>
                        @if (! empty($detail['url']))
                            <a href="{{ $detail['url'] }}">{{ $detail['value'] ?? '' }}</a>
                        @else
                            <span>{{ $detail['value'] ?? '' }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </aside>

        <div class="pb-contact-form">
            <livewire:contact-form
                :form-heading="$block->value('form_heading')"
                :button-label="$block->value('button_label')"
                :success-message="$block->value('success_message')"
            />
        </div>
    </div>
</section>
