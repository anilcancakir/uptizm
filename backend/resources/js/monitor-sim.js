/*
 * Drives the hero panel as a running monitor: a check fires against one region at
 * a time, its latency lands, its bar moves, its state colour follows, and the
 * monitor's overall verdict rolls up from whatever the regions currently say.
 *
 * This replaced a set of looping CSS keyframes. Those animated correctly and were
 * still reported as "no movement", because ambient pulsing is not what a
 * monitoring panel doing its job looks like. What reads as alive is the NUMBERS
 * changing.
 *
 * Honesty boundary: the panel is an illustration, labelled as one, against the
 * placeholder host `api.acme.com`. It is a demo of the interface, not a report on
 * anything, and it must never be mistaken for our own status. Nothing here is
 * presented as measured, and the page's real claims are all derived server-side
 * (see ShowLandingController).
 *
 * Two things it deliberately does not do:
 *   - run under `prefers-reduced-motion: reduce`, where the server-rendered
 *     values simply stay put and are already correct
 *   - run while scrolled out of view, because a marketing page has no business
 *     burning a laptop's battery on an animation nobody is looking at
 */

/** Latency multiplier for a degraded check, and for a recovered one. */
const DEGRADED_FACTOR = [6, 14];

/** How often a check lands, in ms. Kept slower than the eye's patience for a
 *  static page but faster than the 30s the panel claims, because a visitor will
 *  not wait half a minute to see the thing work. */
const CHECK_EVERY = 1750;

/*
 * Probability a check comes back degraded, then down.
 *
 * Tuned against a measured run, not guessed. The first values (0.13 / 0.045) put
 * the panel on "Major outage" for ten seconds out of twenty, because a failure
 * persisted until that region's next round-robin turn nearly nine seconds later,
 * and five regions each failing 17% of the time means something is broken 62% of
 * the time. A hero advertising a product that is down more than it is up is worse
 * than a hero with no motion.
 *
 * With the priority recheck below, a failure now occupies about one tick, so these
 * read as occasional blips against a mostly green panel.
 */
const P_DEGRADED = 0.11;
const P_DOWN = 0.028;

/** Chance a region that just failed comes back healthy on its priority recheck.
 *  Not 1: an outage that always clears on the very next check would be its own
 *  kind of lie. */
const P_RECOVER = 0.82;

const jitter = (base, spread = 0.22) => {
    const delta = base * spread;

    return Math.max(1, Math.round(base - delta + Math.random() * delta * 2));
};

const pick = ([min, max]) => min + Math.random() * (max - min);

/**
 * Roll the regions up into one verdict.
 *
 * ONE region timing out is not an outage, it is one unhappy network path, and
 * calling it an outage is exactly the false page the "Signal, not noise" section
 * of this page is about. So a single failure reads as degraded and it takes two
 * before the monitor is called down. The panel demonstrates the behaviour the copy
 * beside it describes.
 */
function rollup(states) {
    const down = states.filter((state) => state === 'down').length;

    if (down >= 2) {
        return 'down';
    }

    return down === 1 || states.includes('degraded') ? 'degraded' : 'up';
}

export default function initMonitorSim() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const panel = document.querySelector('[data-monitor-sim]');

    if (!panel) {
        return;
    }

    const rows = Array.from(panel.querySelectorAll('[data-region]'));
    const badge = panel.querySelector('[data-rollup]');
    const badgeLabel = panel.querySelector('[data-rollup-label]');
    const today = panel.querySelector('[data-today]');
    const progress = panel.querySelector('[data-check-progress]');

    if (rows.length === 0) {
        return;
    }

    // Labels come from the server so this file carries no copy of its own and
    // stays translatable.
    const labels = {
        up: badge?.dataset.labelUp ?? 'Operational',
        degraded: badge?.dataset.labelDegraded ?? 'Degraded',
        down: badge?.dataset.labelDown ?? 'Major outage',
    };
    const timeoutText = rows[0].dataset.labelTimeout ?? 'timeout';

    const scaleMs = Number(panel.dataset.scaleMs || 110);
    let cursor = 0;
    let timer = null;

    const paint = (row, state, ms) => {
        row.dataset.state = state;

        const value = row.querySelector('[data-region-ms]');
        const bar = row.querySelector('[data-region-bar]');

        if (value) {
            value.textContent = state === 'down' ? timeoutText : `${ms} ms`;
        }

        if (bar) {
            // A down check measured nothing, so its bar empties rather than
            // showing a length it never had.
            bar.style.width = state === 'down' ? '0%' : `${Math.min(100, Math.round((ms / scaleMs) * 100))}%`;
        }
    };

    const runCheck = () => {
        /*
         * A region that is currently failing jumps the queue. Two reasons, and
         * both matter: a real monitor re-checks a failing target sooner than it
         * walks the rest of the rotation, and without it a failure sat on screen
         * for a full lap (five regions, nearly nine seconds) which read as a
         * sustained outage rather than the blip it was meant to be.
         */
        const failing = rows.find((r) => r.dataset.state !== 'up');
        const row = failing ?? rows[cursor % rows.length];

        if (!failing) {
            cursor += 1;
        }

        // The row flashes for the duration of its check, restarted each time by
        // removing and re-adding the class across a reflow.
        row.classList.remove('is-checking');
        void row.offsetWidth;
        row.classList.add('is-checking');
        window.setTimeout(() => row.classList.remove('is-checking'), 900);

        const baseline = Number(row.dataset.baselineMs || 40);
        const roll = Math.random();

        if (failing) {
            // A recheck mostly recovers, and otherwise softens a timeout into a
            // slow response, which is the usual shape of something coming back.
            if (roll < P_RECOVER) {
                paint(row, 'up', jitter(baseline));
            } else {
                paint(row, 'degraded', jitter(Math.round(baseline * pick(DEGRADED_FACTOR)), 0.15));
            }
        } else if (roll < P_DOWN) {
            paint(row, 'down', 0);
        } else if (roll < P_DOWN + P_DEGRADED) {
            paint(row, 'degraded', jitter(Math.round(baseline * pick(DEGRADED_FACTOR)), 0.15));
        } else {
            paint(row, 'up', jitter(baseline));
        }

        const verdict = rollup(rows.map((r) => r.dataset.state));

        if (badge) {
            badge.dataset.state = verdict;
        }

        if (badgeLabel) {
            badgeLabel.textContent = labels[verdict];
        }

        // The newest segment of the history is today, still in progress, so it
        // tracks the current verdict rather than sitting on a fixed colour.
        if (today) {
            today.dataset.state = verdict;
        }

        if (progress) {
            progress.classList.remove('is-counting');
            void progress.offsetWidth;
            progress.classList.add('is-counting');
        }
    };

    const start = () => {
        if (timer === null) {
            runCheck();
            timer = window.setInterval(runCheck, CHECK_EVERY);
        }
    };

    const stop = () => {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    };

    // Only while on screen, and only while the tab is in front.
    new IntersectionObserver(
        (entries) => entries.forEach((entry) => (entry.isIntersecting && !document.hidden ? start() : stop())),
        { threshold: 0.15 },
    ).observe(panel);

    document.addEventListener('visibilitychange', () => (document.hidden ? stop() : null));
}
