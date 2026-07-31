import Alpine from 'alpinejs';

import { heroSequence } from './components/heroSequence';

// This bundle serves the MARKETING surface only. The product itself is the Flutter
// client on its own host, so nothing here is application state, and the public status
// page loads the stylesheet but NOT this file, so nothing that page depends on may end
// up here.
//
// Registered before start(), and this app owns Alpine outright: unlike fluttersdk.com
// there is no Livewire here that would boot a second instance alongside it.
Alpine.data('heroSequence', heroSequence);

window.Alpine = Alpine;

Alpine.start();

/*
 * The entrance.
 *
 * The hidden state lives behind `.js-motion`, which an inline snippet in the layout
 * sets before first paint so content is never shown and then hidden. Claiming
 * `motionReady` here is what disarms that snippet's failsafe; without it the left
 * column sat blank for the full 2.5s timeout before the failsafe un-hid it, which is
 * the failsafe doing its job as the primary path.
 *
 * No IntersectionObserver: the hero is above the fold, so there is nothing to wait
 * for, and one less gate is one less dead state.
 */
if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.documentElement.dataset.motionReady = '1';

    document.querySelectorAll('[data-enter]').forEach((el, i) => {
        if (!el.style.getPropertyValue('--enter-index')) {
            el.style.setProperty('--enter-index', String(i));
        }

        el.classList.add('is-in');
    });
}
