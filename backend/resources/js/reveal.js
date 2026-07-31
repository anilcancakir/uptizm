/*
 * Scroll reveal for the marketing page.
 *
 * The hidden-until-revealed state lives in CSS behind `.js-reveal` on <html>,
 * which an inline snippet in the layout head sets before first paint (adding it
 * from this deferred module would show the content, then hide it, then animate
 * it back in). That snippet also arms a failsafe timer: if this module never
 * reports ready, it removes the class so nothing stays hidden behind a script
 * that failed to load.
 *
 * This module is the other half of that contract. It sets `revealReady` as soon
 * as it can honour the hidden state, and clears the class itself if it cannot.
 */

/**
 * Number each element among the siblings it shares an attribute with, so a grid
 * or a row can stagger. Done here rather than in Blade because the same rule
 * then applies to every group without each template repeating a loop counter.
 */
function index(elements, property) {
    const seen = new Map();

    elements.forEach((element) => {
        const parent = element.parentElement;
        const next = (seen.get(parent) ?? 0);

        element.style.setProperty(property, String(next));
        seen.set(parent, next + 1);
    });
}

export default function initReveal() {
    const root = document.documentElement;

    // Reduced motion, or the head snippet decided not to arm: nothing to do,
    // and nothing is hidden.
    if (!root.classList.contains('js-reveal')) {
        return;
    }

    // No IntersectionObserver means no way to know when to reveal, so give up
    // the hidden state rather than gambling with it.
    if (!('IntersectionObserver' in window)) {
        root.classList.remove('js-reveal');

        return;
    }

    root.dataset.revealReady = '1';

    const items = Array.from(document.querySelectorAll('[data-reveal]'));
    const bars = Array.from(document.querySelectorAll('[data-bar]'));
    const tracks = Array.from(document.querySelectorAll('[data-days]'));

    index(items, '--reveal-index');
    index(bars, '--reveal-index');
    tracks.forEach((track) => index(Array.from(track.querySelectorAll('[data-day]')), '--day-index'));

    const reveal = (element) => element.classList.add('is-revealed');

    /*
     * A negative bottom margin holds the trigger until the element is properly
     * on screen rather than one pixel into it; 12% down the viewport is roughly
     * where a reader's attention already is.
     */
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                // A day track reveals its whole series at once: ninety bars on
                // one line are a single visual unit, and observing each of them
                // separately would cost 90 observations to learn the same fact.
                if (entry.target.hasAttribute('data-days')) {
                    entry.target.querySelectorAll('[data-day]').forEach(reveal);
                } else {
                    reveal(entry.target);
                }

                // One-shot. Re-animating on the way back up is the kind of
                // effect that turns into a nuisance on a long page.
                observer.unobserve(entry.target);
            });
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.01 },
    );

    [...items, ...bars, ...tracks].forEach((element) => observer.observe(element));
}
