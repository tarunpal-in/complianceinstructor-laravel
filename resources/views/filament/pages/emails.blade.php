@php
    $budget = $this->budget();
    $groups = $this->rows();
    $mayManage = $this->mayManage();
@endphp

<x-filament-panels::page>
    {{-- Halted is stated at the top and impossible to miss. Somebody who has
         forgotten they stopped sending will spend a long time wondering why
         nothing arrives. --}}
    @if ($budget['halted'])
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-6 w-6 shrink-0 text-danger-600 dark:text-danger-400" />
                <div>
                    <h3 class="font-semibold text-danger-600 dark:text-danger-400">All sending is stopped</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Nothing is going out, including password resets and receipts. Everything that would have been
                        sent is in the email log, marked as not sent.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- The allowance.

         Here because this is where somebody stands before scheduling something,
         and because a consumer Gmail account answers an over-run by blocking the
         whole mailbox for about 24 hours — password resets with it. Nothing else
         in the application would tell them how close they are. --}}
    <x-filament::section>
        <x-slot name="heading">Today's sending allowance</x-slot>
        <x-slot name="description">
            Gmail allows a limited number of messages in any 24 hours. Going over blocks the account for about a day,
            so the figures below are set below the real ceiling on purpose.
        </x-slot>

        <div class="grid gap-4 sm:grid-cols-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Sent in 24 hours</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                    {{ number_format($budget['used']) }}
                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400">of {{ number_format($budget['dailyCap']) }}</span>
                </p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Left for receipts &amp; resets</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($budget['remainingTransactional']) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Left for reminders &amp; marketing</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($budget['remainingBulk']) }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Capped at {{ number_format($budget['bulkCap']) }}, so a blast cannot use up the rest</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Waiting for allowance</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($budget['deferred']) }}</p>
                @if ($budget['deferred'] > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400">These go out as the allowance frees up</p>
                @endif
            </div>
        </div>

        <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
            <div
                class="h-full rounded-full {{ $budget['nearLimit'] ? 'bg-danger-500' : 'bg-primary-500' }}"
                style="width: {{ max(2, (int) $budget['percent']) }}%"
            ></div>
        </div>

        @if ($budget['nearLimit'])
            <p class="mt-3 text-sm font-medium text-danger-600 dark:text-danger-400">
                Close to the limit. Anything further will wait rather than fail — but avoid scheduling a large send today.
            </p>
        @endif

        @if ($budget['failed'] > 0)
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                {{ $budget['failed'] }} {{ Str::plural('email', $budget['failed']) }} failed in the last 7 days.
                <a href="{{ \App\Filament\Resources\EmailLog\EmailSendResource::getUrl('index', panel: 'admin') }}" class="font-medium text-primary-600 underline dark:text-primary-400">
                    Look at the log
                </a>
            </p>
        @endif

        {{-- Said plainly, where sends are planned rather than buried in settings.
             Staff who read "Sent" as "Delivered" will tell a customer their
             certificate definitely arrived. --}}
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Gmail accepting a message is not the same as delivering it. Nothing here can confirm delivery &mdash; if
            somebody says an email never arrived, look for the bounce in the sending mailbox using the message id on
            that email's page in the log.
        </p>
    </x-filament::section>

    @foreach ($groups as $groupName => $rows)
        <x-filament::section collapsible>
            <x-slot name="heading">{{ $groupName }}</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rows as $row)
                    @php $email = $row->email; @endphp
                    <div class="flex flex-col gap-3 py-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-950 dark:text-white">{{ $email->name }}</p>
                            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{{ $email->trigger }}</p>

                            @if (! $email->isBuilt())
                                <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                                    Not built yet &mdash; {{ $email->blockedBy }}
                                </p>
                            @elseif (! $email->tier->isSwitchable())
                                {{-- The reason, not just the lock. If the client
                                     disagrees it should be a conversation. --}}
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    <strong>Always sent.</strong> {{ $email->lockReason }}
                                </p>
                            @endif

                            @if ($email->isBuilt())
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row->sent }} sent in the last 30 days
                                    @if ($row->lastSent)
                                        &middot; last {{ $row->lastSent->diffForHumans() }}
                                    @endif
                                </p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if (! $email->isBuilt())
                                <x-filament::badge color="gray">Not built</x-filament::badge>
                            @elseif (! $email->tier->isSwitchable())
                                <x-filament::badge color="danger" icon="heroicon-m-lock-closed">Always on</x-filament::badge>
                            @else
                                <x-filament::badge :color="$row->enabled ? 'success' : 'gray'">
                                    {{ $row->enabled ? 'On' : 'Off' }}
                                </x-filament::badge>

                                @if ($mayManage)
                                    <x-filament::button
                                        size="xs"
                                        :color="$row->enabled ? 'gray' : 'primary'"
                                        wire:click="toggle(@js($email->key))"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ $row->enabled ? 'Switch off' : 'Switch on' }}
                                    </x-filament::button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
