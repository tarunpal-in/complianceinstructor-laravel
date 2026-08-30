@props(['plans' => []])

<section class="pricing section" id="memberships">
    <div class="section-heading centered">
        <div class="eyebrow">FLEXIBLE PRICING</div>
        <h2>Membership Plans</h2>
        <p>Invest in your professional development with a plan that matches your learning pace.</p>
    </div>

    <div class="plans-grid">
        @foreach ($plans as $plan)
            <article @class(['plan', 'popular' => $plan['popular']])>
                @if ($plan['popular'])
                    <span class="popular-tag">MOST POPULAR</span>
                @endif

                {{-- Sentence case, not uppercase: the comp renders the plan name
                     as-is and leans on `.plan h3 { letter-spacing: .1em }`. --}}
                <h3>{{ $plan['name'] }}</h3>

                <div class="price">${{ number_format($plan['price_cents'] / 100) }} <small>/ {{ $plan['term'] }}</small></div>

                <b class="live-count">{{ $plan['allowance'] }}</b>

                <hr>

                @foreach ($plan['features'] as $feature)
                    <p class="feature"><x-ui.icon name="check" :size="16" />{{ $feature }}</p>
                @endforeach

                @foreach ($plan['muted'] as $feature)
                    <p class="feature muted"><span aria-hidden="true">⊗</span> {{ $feature }}</p>
                @endforeach

                <button @click="showNotice('{{ $plan['name'] }} plan selected')">Get Started</button>
            </article>
        @endforeach
    </div>

    <div class="callback">
        <div>
            <strong>Not sure which plan is right for you?</strong>
            <span>Our team can help match you with the best training package for your goals and budget.</span>
        </div>
        <button @click="showNotice('We will be in touch soon')">Request a Callback</button>
    </div>
</section>
