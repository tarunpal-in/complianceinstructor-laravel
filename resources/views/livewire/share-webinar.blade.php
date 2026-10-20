<div x-data="{ open: false }" class="share-webinar">
    <button type="button" class="btn-ghost" @click="open = true">
        <x-ui.icon name="share-2" :size="18" /> Share Webinar
    </button>

    {{-- Modal --}}
    <div x-show="open" x-cloak class="share-backdrop"
         @keydown.escape.window="open = false"
         @click.self="open = false">
        <div class="share-modal" role="dialog" aria-modal="true" aria-label="Share this webinar" data-lenis-prevent>
            <button type="button" class="share-close" @click="open = false" aria-label="Close">
                <x-ui.icon name="x" :size="18" />
            </button>

            @if ($sent)
                <div class="share-done">
                    <span class="share-done-tick"><x-ui.icon name="check" :size="26" /></span>
                    <h3>Webinar shared</h3>
                    <p>We've emailed the details. Thanks for spreading the word.</p>
                    <button type="button" class="btn-ghost" wire:click="resetForm">Share with someone else</button>
                </div>
            @else
                <h3 class="share-title">Share this webinar</h3>
                <p class="share-sub">We'll email <strong>{{ $webinar->title }}</strong> and its details to the person you choose.</p>

                <form wire:submit="send" class="share-form">
                    {{-- Honeypot: hidden from people, tempting to bots. --}}
                    <div class="share-hp" aria-hidden="true">
                        <label>Company website<input type="text" wire:model="company_website" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <label class="share-field">
                        <span>Your name</span>
                        <input type="text" wire:model="sharerName" autocomplete="name">
                        @error('sharerName') <em>{{ $message }}</em> @enderror
                    </label>

                    <label class="share-field">
                        <span>Your email</span>
                        <input type="email" wire:model="sharerEmail" autocomplete="email">
                        @error('sharerEmail') <em>{{ $message }}</em> @enderror
                    </label>

                    <label class="share-field">
                        <span>Recipient's email</span>
                        <input type="email" wire:model="recipient" placeholder="colleague@example.com">
                        @error('recipient') <em>{{ $message }}</em> @enderror
                    </label>

                    <label class="share-field">
                        <span>Message <small>(optional)</small></span>
                        <textarea wire:model="note" rows="3" placeholder="Thought this would be useful for your team…"></textarea>
                        @error('note') <em>{{ $message }}</em> @enderror
                    </label>

                    <button type="submit" class="btn-primary share-submit" wire:loading.attr="disabled" wire:target="send">
                        <span wire:loading.remove wire:target="send">Send webinar</span>
                        <span wire:loading wire:target="send">Sending…</span>
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
