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
 * No IntersectionObserver for the hero's own entrance: it is above the fold, so there is
 * nothing to wait for, and one less gate is one less dead state.
 *
 * None of this is gated on the motion preference any more. It was, and the result was that
 * a visitor with reduced motion set had `js-motion` withheld and this observer never
 * created, so every section below the hero arrived already visible and never animated at
 * all. The preference is CSS's decision now: app.css slides under no-preference and
 * cross-fades under reduce, and this file just reports that the page can be trusted with a
 * hidden state.
 */
{
    document.documentElement.dataset.motionReady = '1';

    document.querySelectorAll('[data-enter]').forEach((el, i) => {
        if (!el.style.getPropertyValue('--enter-index')) {
            el.style.setProperty('--enter-index', String(i));
        }

        el.classList.add('is-in');
    });

    /*
     * Everything below the fold arrives on scroll instead. One observer for the whole
     * page, and each element is unobserved the moment it lands: a reveal is a first
     * impression, so replaying it on the way back up would turn a considered entrance
     * into a twitch.
     *
     * The bottom margin means an element reveals slightly before it reaches the edge of
     * the viewport, which is what stops the animation from looking like it is chasing
     * the scroll.
     */
    const reveals = new IntersectionObserver(
        (entries, observer) =>
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-in');
                observer.unobserve(entry.target);
            }),
        { threshold: 0.1, rootMargin: '0px 0px -8% 0px' },
    );

    document.querySelectorAll('[data-reveal]').forEach((el) => reveals.observe(el));
}
