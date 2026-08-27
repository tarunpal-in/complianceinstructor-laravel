import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

// CLAUDE.md §3 — Blade + Livewire + Alpine, with Livewire reserved for places
// where state is real (cart, checkout, catalogue filters). The public marketing
// pages need only Alpine: mobile navigation and the header dropdowns.
Alpine.plugin(collapse);

window.Alpine = Alpine;

Alpine.start();
