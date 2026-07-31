/*
 * Drives the hero panel as a running monitor.
 *
 * A cycle fans out to EVERY region at once, which is what the product does:
 * `ScheduleMonitorChecks` dispatches one job per region inside a single tick (see
 * its fan-out loop), so a monitor's regions are probed in parallel and never one
 * after another. An earlier version walked them round-robin, which was simply a
 * different product from the one this page is selling.
 *
 * Results then land in LATENCY ORDER, each after a delay derived from the number
 * it is about, so the fast region settles first and a timeout settles last. That
 * ordering is not decoration: it is the one thing about a parallel fan-out a
 * viewer can actually see.
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

/*
 * Gap between checks, in ms, drawn fresh each time.
 *
 * Random rather than fixed because a metronome reads as an animation while an
 * uneven cadence reads as work arriving. Both bounds are deliberate: under a
 * second the panel becomes a slot machine, and over five the visitor concludes
 * nothing is happening. Faster than the 30s the panel claims, because nobody
 * waits half a minute to see whether a hero works.
 */
const GAP_MS = [1000, 5000];

/*
 * Probability a single region's check comes back degraded, then down.
 *
 * These have to be read per CYCLE, not per check, and that is why they are small.
 * Every region is probed every cycle now, so the chance the panel shows a problem
 * is 1 - (1 - p)^5, which compounds fast: the round-robin era's 0.11/0.028 would
 * put something in a failed state 52% of the time. An earlier version of exactly
 * that mistake had the panel reading "Major outage" for ten seconds out of twenty,
 * and a hero advertising a product that is broken more than it works is worse than
 * a hero that does not move.
 *
 * Frequency is the only real lever on how much of the WALL CLOCK the panel spends
 * unhealthy, because a failed region necessarily stays failed until its next
 * result, which is a whole cycle away (~3.7s). Instrumenting the first parallel
 * version showed the code hitting its stated 4.4% per region and 17% of cycles
 * exactly as designed, while the badge still read degraded 46% of the time: the
 * rate was right and the DURATION was doing the damage. Hence these lower values
 * and a tighter recovery.
 */
const P_DEGRADED = 0.022;
const P_DOWN = 0.006;

/** Chance a region that failed last cycle comes back healthy on the next one. Not
 *  1: an outage that always clears on the very next check would be its own kind of
 *  lie. */
const P_RECOVER = 0.9;

/*
 * How long a result takes to land, from the number it reports. A fast region
 * settles almost at once, a slow one visibly later, a timeout last of all because
 * a timeout is the full window elapsing. Amplified from real milliseconds, which
 * are far too quick to perceive, and capped so one slow region cannot hold the
 * whole cycle open.
 */
const settleDelay = (state, ms) => {
    if (state === 'down') {
        return 1300;
    }

    return 90 + Math.min(300, ms) * 3;
};

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
    let timer = null;

    /*
     * Running requires BOTH conditions, tracked separately.
     *
     * The first version derived this from the IntersectionObserver alone and had
     * `visibilitychange` only ever call stop(). Switching to another window
     * therefore killed the simulation permanently: coming back set
     * `document.hidden` to false, but the observer does not re-fire because the
     * intersection never changed. Reported as "it ran once and then stopped",
     * which is precisely what it did.
     */
    let inView = false;
    let visible = !document.hidden;

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

    /** Decide one region's outcome for this cycle. */
    const resolve = (row) => {
        const baseline = Number(row.dataset.baselineMs || 40);
        const roll = Math.random();
        const slow = () => jitter(Math.round(baseline * pick(DEGRADED_FACTOR)), 0.15);

        // A region that failed last cycle mostly recovers, and otherwise softens a
        // timeout into a slow response, which is the usual shape of something
        // coming back.
        if (row.dataset.state !== 'up') {
            return roll < P_RECOVER ? { state: 'up', ms: jitter(baseline) } : { state: 'degraded', ms: slow() };
        }

        if (roll < P_DOWN) {
            return { state: 'down', ms: 0 };
        }

        return roll < P_DOWN + P_DEGRADED
            ? { state: 'degraded', ms: slow() }
            : { state: 'up', ms: jitter(baseline) };
    };

    const refreshVerdict = () => {
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
    };

    /**
     * One check cycle: every region at once, results landing in latency order.
     */
    const runCycle = () => {
        // All rows go in-flight together, which is the visible difference between
        // a parallel fan-out and a rotation. Restarting the flash needs the class
        // removed and re-added across a reflow.
        rows.forEach((row) => {
            row.classList.remove('is-checking');
            void row.offsetWidth;
            row.classList.add('is-checking');
        });

        let lastSettle = 0;

        rows.forEach((row) => {
            const { state, ms } = resolve(row);
            const delay = settleDelay(state, ms);

            lastSettle = Math.max(lastSettle, delay);

            // Each result lands on its own clock, so the row that answers in 12ms
            // is done long before the one that times out.
            window.setTimeout(() => {
                row.classList.remove('is-checking');
                paint(row, state, ms);
                refreshVerdict();
            }, delay);
        });

        /*
         * The next cycle is measured from when THIS one finished settling, not from
         * when it started, so a slow cycle cannot overlap the following one and
         * leave two sets of results racing into the same rows.
         */
        const gap = Math.round(pick(GAP_MS));

        if (progress) {
            progress.style.animationDuration = `${lastSettle + gap}ms`;
            progress.classList.remove('is-counting');
            void progress.offsetWidth;
            progress.classList.add('is-counting');
        }

        timer = window.setTimeout(runCycle, lastSettle + gap);
    };

    const start = () => {
        if (timer === null) {
            runCycle();
        }
    };

    const stop = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    };

    // One place decides, so neither signal can leave the other's state stale.
    const sync = () => (inView && visible ? start() : stop());

    new IntersectionObserver(
        (entries) =>
            entries.forEach((entry) => {
                inView = entry.isIntersecting;
                sync();
            }),
        { threshold: 0.15 },
    ).observe(panel);

    document.addEventListener('visibilitychange', () => {
        visible = !document.hidden;
        sync();
    });
}
