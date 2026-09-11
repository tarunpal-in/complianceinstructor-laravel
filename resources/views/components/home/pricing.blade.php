@props(['plans' => collect()])

{{--
    The pricing cards, from `membership_plans` (§5.5).

    The price, term and quota badge are all derived from the record — the badge
    from `plan_entitlements`, so the number a customer reads here is the number
    they are actually granted. The tick-and-cross list is `highlights`, which is
    copy the client edits and which enforces nothing.
--}}
<section class="pricing section" id="memberships">
    <div class="section-heading centered">
        <div class="eyebrow">FLEXIBLE PRICING</div>
        <h2>Membership Plans</h2>
        <p>Invest in your professional development with a plan that matches your learning pace.</p>
    </div>

    @if ($plans->isNotEmpty())
        <div class="plans-grid" style="--plan-columns: {{ min($plans->count(), 4) }}">
            @foreach ($plans as $plan)
                <article @class(['plan', 'popular' => $plan->is_featured])>
                    @if ($plan->is_featured)
                        <span class="popular-tag">MOST POPULAR</span>
                    @endif

                    {{-- Sentence case, not uppercase: the comp renders the plan name
                         as-is and leans on `.plan h3 { letter-spacing: .1em }`. --}}
                    <h3>{{ $plan->name }}</h3>

                    <div class="price">{{ $plan->cardPrice() }} <small>/ {{ $plan->describeTerm() }}</small></div>

                    @if ($headline = $plan->describeHeadline())
                        <b class="live-count">{{ $headline }}</b>
                    @endif

                    <hr>

                    @foreach ($plan->highlightRows() as $highlight)
                        <p @class(['feature', 'muted' => ! $highlight['included']])>
                            @if ($highlight['included'])
                                <x-ui.icon name="check" :size="16" />
                            @else
                                <span aria-hidden="true">⊗</span>
                            @endif
                            {{ $highlight['label'] }}
                        </p>
                    @endforeach

                    <button @click="showNotice('{{ $plan->name }} plan selected')">Get Started</button>
                </article>
            @endforeach
        </div>
    @endif

    <div class="callback">
        <div>
            <strong>Not sure which plan is right for you?</strong>
            <span>Our team can help match you with the best training package for your goals and budget.</span>
        </div>
        <button @click="showNotice('We will be in touch soon')">Request a Callback</button>
    </div>
</section>
