<x-filament-panels::page>
    @php $certificates = $this->certificates(); @endphp

    @forelse ($certificates as $certificate)
        <div class="ld-card">
            <div class="ld-card-top">
                <div class="ld-card-main">
                    <h3 class="ld-card-title">{{ $certificate->webinar_title }}</h3>
                    <p class="ld-card-speaker">{{ $certificate->describeCredits() }}</p>
                    <p class="ld-card-date">Issued {{ $certificate->issued_at->format('j F Y') }}</p>
                    <p class="ld-cert-serial">Serial {{ $certificate->serial }}</p>
                </div>

                <div class="ld-card-action">
                    <span class="ld-cert-badge {{ $certificate->isRevoked() ? 'is-revoked' : 'is-valid' }}">
                        {{ $certificate->describeState() }}
                    </span>

                    @if ($certificate->isValid())
                        <a href="{{ route('learning.certificate', $certificate) }}"
                           class="ld-btn" target="_blank" rel="noopener">
                            Download certificate
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="ld-empty">
            <span class="ld-empty-icon">
                <x-filament::icon icon="heroicon-o-academic-cap" class="h-7 w-7" />
            </span>
            <p class="ld-empty-text">You have no certificates yet. They appear here once you complete an accredited webinar.</p>
            <a href="{{ route('webinars.index') }}" class="ld-empty-link">Browse webinars</a>
        </div>
    @endforelse
</x-filament-panels::page>
