@props(['webinar', 'membership'])

{{-- Shown only to a signed-in member whose plan touches this webinar (§5.2 / P5).
     Each format the plan includes is in one of three states, computed in the
     controller: held (open it), spendable (redeem now), exhausted (used up). --}}
<div class="membership-panel">
    <h3>Included with your membership</h3>

    @if (session('membershipStatus'))
        <p class="mp-flash mp-flash-ok" role="status">{{ session('membershipStatus') }}</p>
    @endif
    @if (session('membershipError'))
        <p class="mp-flash mp-flash-error" role="alert">{{ session('membershipError') }}</p>
    @endif

    <ul class="mp-formats">
        @foreach ($membership['formats'] as $row)
            @php $format = $row['format']; @endphp
            <li>
                <span class="mp-format-name">{{ $format->label() }}</span>

                @if ($row['state'] === 'held')
                    <a class="mp-open"
                       href="{{ route('learning.access', [$webinar->slug, $format->value]) }}"
                       @if ($format === \App\Enums\Format::Live) target="_blank" rel="noopener" @endif>
                        {{ $format === \App\Enums\Format::Live ? 'Join session' : 'Open' }}
                    </a>
                @elseif ($row['state'] === 'spendable')
                    <form method="POST" action="{{ route('membership.watch', $webinar->slug) }}">
                        @csrf
                        <input type="hidden" name="format" value="{{ $format->value }}">
                        <button type="submit" class="mp-redeem">Watch with your membership</button>
                        <span class="mp-remaining">{{ $row['remaining'] }}</span>
                    </form>
                @else
                    <span class="mp-exhausted">Allowance used this period</span>
                @endif
            </li>
        @endforeach
    </ul>
</div>
