{{-- "Membership Plans", driven by its `home_pricing` block. The plan cards stay
     live from `membership_plans` ($plans, passed in); only the wrapper copy and
     the callback are editable. --}}
<x-home.pricing
    :plans="$plans"
    :eyebrow="$block->value('eyebrow')"
    :heading="$block->value('heading')"
    :intro="$block->value('intro')"
    :callback-heading="$block->value('callback_heading')"
    :callback-body="$block->value('callback_body')"
    :callback-button="$block->value('callback_button')"
    :callback-url="$block->value('callback_url')"
/>
