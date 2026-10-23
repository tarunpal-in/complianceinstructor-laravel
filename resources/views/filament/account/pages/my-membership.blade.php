<x-filament-panels::page>
    @php $subscription = $this->membership(); @endphp

    @if ($subscription === null)
        <div class="ld-empty">
            <span class="ld-empty-icon">
                <x-filament::icon icon="heroicon-o-user-group" class="h-7 w-7" />
            </span>
            <p class="ld-empty-text">You don't have a membership yet.</p>
            <a href="{{ route('membership.index') }}" class="ld-empty-link">See membership plans</a>
        </div>
    @else
        <div class="ld-card">
            <div class="ld-card-top">
                <div class="ld-card-main">
                    <h3 class="ld-card-title">{{ $subscription->plan?->name ?? 'Membership' }}</h3>
                    <p class="ld-card-date">{{ $subscription->describeTermDates() }}</p>
                </div>
                <span class="ld-mb-status {{ $subscription->state()->grantsAccess() ? 'is-active' : 'is-inactive' }}">
                    {{ $subscription->state()->label() }}
                </span>
            </div>

            <div class="ld-mb-allowance">
                <span class="ld-mb-allowance-label">Your allowance</span>
                <ul class="ld-mb-list">
                    @foreach (\App\Enums\Format::cases() as $format)
                        @if ($subscription->allowanceFor($format) !== null)
                            <li>
                                <span>{{ $format->label() }}</span>
                                <strong>{{ $subscription->describeRemaining($format) }}</strong>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>

            <div class="ld-card-action">
                <a href="{{ route('membership.manage') }}" class="ld-btn">Manage membership</a>
            </div>
        </div>
    @endif
</x-filament-panels::page>
