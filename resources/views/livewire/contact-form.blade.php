<div class="pb-cf">
    @if ($sent)
        <div class="pb-cf-sent" role="status">
            <span class="pb-cf-sent-icon"><x-ui.icon name="check" :size="26" /></span>
            <p>{{ $successMessage }}</p>
            <button type="button" class="pb-cf-again" wire:click="$set('sent', false)">Send another message</button>
        </div>
    @else
        <h3 class="pb-cf-heading">{{ $formHeading }}</h3>

        <form wire:submit="submit" class="pb-cf-form">
            <div class="pb-cf-row">
                <label class="pb-cf-field">
                    <span>Name <i>*</i></span>
                    <input type="text" wire:model="name" autocomplete="name">
                    @error('name') <em>{{ $message }}</em> @enderror
                </label>
                <label class="pb-cf-field">
                    <span>Email <i>*</i></span>
                    <input type="email" wire:model="email" autocomplete="email">
                    @error('email') <em>{{ $message }}</em> @enderror
                </label>
            </div>

            <div class="pb-cf-row">
                <label class="pb-cf-field">
                    <span>Company</span>
                    <input type="text" wire:model="company" autocomplete="organization">
                    @error('company') <em>{{ $message }}</em> @enderror
                </label>
                <label class="pb-cf-field">
                    <span>Phone</span>
                    <input type="tel" wire:model="phone" autocomplete="tel">
                    @error('phone') <em>{{ $message }}</em> @enderror
                </label>
            </div>

            <label class="pb-cf-field">
                <span>Subject</span>
                <input type="text" wire:model="subject">
                @error('subject') <em>{{ $message }}</em> @enderror
            </label>

            <label class="pb-cf-field">
                <span>Message <i>*</i></span>
                <textarea wire:model="message" rows="5"></textarea>
                @error('message') <em>{{ $message }}</em> @enderror
            </label>

            <button type="submit" class="pb-cf-submit" wire:loading.attr="disabled" wire:target="submit">
                <span wire:loading.remove wire:target="submit">{{ $buttonLabel }}</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </button>
        </form>
    @endif
</div>
