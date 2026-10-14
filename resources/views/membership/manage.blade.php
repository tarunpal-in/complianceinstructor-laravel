@extends('layouts.public')

@php use App\Support\Money; @endphp

@section('content')
    <section class="section mb-manage">
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="{{ route('membership.index') }}">Memberships</a></li>
                <li aria-current="page">Your membership</li>
            </ol>
        </nav>

        <h1>Your membership</h1>

        @if (session('membershipStatus'))
            <p class="mb-flash mb-flash-ok" role="status">{{ session('membershipStatus') }}</p>
        @endif
        @if (session('membershipError'))
            <p class="mb-flash mb-flash-error" role="alert">{{ session('membershipError') }}</p>
        @endif

        <div class="mb-manage-card">
            <div class="mb-manage-head">
                <div>
                    <h2>{{ $subscription->plan?->name ?? 'Membership' }}</h2>
                    <p class="mb-manage-term">{{ $subscription->describeTermDates() }}</p>
                </div>
                <span class="mb-state mb-state-{{ $subscription->state()->color() }}">{{ $subscription->state()->label() }}</span>
            </div>

            @if ($subscription->priceCents() !== null)
                <p class="mb-manage-price">{{ Money::format($subscription->priceCents(), $subscription->plan?->currency ?? 'USD') }}
                    @if ($subscription->plan) / {{ $subscription->plan->describeTerm() }} @endif
                </p>
            @endif

            {{-- What's left this window, per format. --}}
            <h3 class="mb-manage-sub">Your allowance</h3>
            <ul class="mb-manage-allowances">
                @foreach ($formats as $format)
                    @php $allowance = $subscription->allowanceFor($format); @endphp
                    @if ($allowance !== null)
                        <li>
                            <span>{{ $format->label() }}</span>
                            <strong>{{ $subscription->describeRemaining($format) }}</strong>
                        </li>
                    @endif
                @endforeach
            </ul>

            {{-- Lifecycle: resume while ending, otherwise cancel. --}}
            <div class="mb-manage-actions">
                @if ($subscription->onGracePeriod())
                    <p class="mb-manage-note">Your membership ends on {{ optional($subscription->accessEndsAt())->format('j M Y') }}. You keep access until then.</p>
                    <form method="POST" action="{{ route('membership.resume') }}">
                        @csrf
                        <button type="submit" class="btn-primary">Resume membership</button>
                    </form>
                @elseif (! $subscription->canceled())
                    <form method="POST" action="{{ route('membership.cancel') }}" onsubmit="return confirm('Cancel your membership at the end of this period?')">
                        @csrf
                        <button type="submit" class="btn-ghost">Cancel membership</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Upgrade / downgrade: swap to another recurring plan, prorated. --}}
        @if ($otherPlans->isNotEmpty() && ! $subscription->onGracePeriod() && ! $subscription->canceled())
            <div class="mb-manage-change">
                <h3 class="mb-manage-sub">Change your plan</h3>
                <p class="mb-manage-note">Switching takes effect immediately; Stripe prorates the difference.</p>
                <div class="mb-change-grid">
                    @foreach ($otherPlans as $plan)
                        <article class="mb-change-plan">
                            <h4>{{ $plan->name }}</h4>
                            <div class="mb-change-price">{{ $plan->cardPrice() }} <small>/ {{ $plan->describeTerm() }}</small></div>
                            <ul>
                                @foreach ($plan->allowanceLines() as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                            <form method="POST" action="{{ route('membership.change') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $plan->slug }}">
                                <button type="submit" class="btn-ghost">Switch to {{ $plan->name }}</button>
                            </form>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endsection
