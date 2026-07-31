/*
 * The hero: a monitor that keeps running, and a spotlight card that takes turns.
 *
 * A cycle fans out to EVERY region at once, which is what the product does.
 * `ScheduleMonitorChecks` dispatches one job per region inside a single tick, so a
 * monitor's regions are probed in parallel, never one after another. Results then
 * land in LATENCY ORDER, each after a delay derived from the number it reports, so
 * the fast region settles first and a timeout settles last. That ordering is the one
 * part of a parallel fan-out a viewer can actually perceive, and it comes free from
 * the data.
 *
 * ONE GATE: is the hero on screen. An earlier version also required the tab to be
 * visible, which created a dead state: a page loaded in a background tab starts with
 * `visible` false, and IntersectionObserver does not fire in a hidden tab, so
 * `inView` never became true either and the panel stayed frozen for the life of the
 * page. Browsers already throttle background timers to about once a minute, so the
 * second condition was buying almost nothing and cost the whole feature twice.
 *
 * Honesty boundary: the panel is an illustration, labelled as one, against the
 * placeholder host `api.acme.com`. It reports on nothing and must never be mistaken
 * for our own status. Every real claim on the page is derived server-side, in
 * ShowLandingController.
 */

/** Gap between check cycles, drawn fresh each time. Uneven reads as work arriving;
 *  a metronome reads as an animation. */
const GAP_MS = [1400, 4200];

/** Multiplier applied to a baseline for a slow response. */
const SLOW_FACTOR = [6, 14];

/*
 * Per-region, per-cycle failure odds. Small on purpose: every region is checked every
 * cycle, so the chance of the panel showing a problem is 1 - (1 - p)^5 and compounds
 * fast. A failed region also necessarily stays failed until its next result a whole
 * cycle away, so frequency is the only real lever on how much of the wall clock the
 * hero spends unhealthy. Earlier values put it at 46%.
 */
const P_SLOW = 0.022;
const P_DOWN = 0.006;

/** Odds a region that failed last cycle comes back. Not 1: an outage that always
 *  clears on the very next check would be its own kind of lie. */
const P_RECOVER = 0.9;

/** How long each scene holds before the spotlight advances. */
const SCENE_MS = 5200;

const jitter = (base, spread = 0.22) => {
    const d = base * spread;

    return Math.max(1, Math.round(base - d + Math.random() * d * 2));
};

const pick = ([min, max]) => min + Math.random() * (max - min);

/**
 * How long a result takes to land, from the number it reports. Fast settles at once,
 * slow visibly later, a timeout last because a timeout is the full window elapsing.
 * Amplified from real milliseconds, which are far too quick to perceive, and capped
 * so one slow region cannot hold the whole cycle open.
 */
const settleDelay = (state, ms) => (state === 'down' ? 1250 : 80 + Math.min(300, ms) * 3);

/**
 * Roll the regions up into one verdict.
 *
 * ONE region timing out is not an outage, it is one unhappy network path, and calling
 * it an outage is the false page this product exists to avoid. So a single failure
 * reads as degraded and it takes two before the monitor is called down.
 */
function rollup(states) {
    const down = states.filter((s) => s === 'down').length;

    if (down >= 2) {
        return 'down';
    }

    return down === 1 || states.includes('degraded') ? 'degraded' : 'up';
}

function runMonitor(panel, reduced) {
    const rows = Array.from(panel.querySelectorAll('[data-region]'));

    if (rows.length === 0) {
        return;
    }

    const verdict = panel.querySelector('[data-verdict]');
    const verdictDot = panel.querySelector('[data-verdict-dot]');
    const today = panel.querySelector('[data-today]');
    const scale = Number(panel.dataset.scaleMs || 110);
    const labels = {
        up: verdict?.dataset.labelUp ?? 'Operational',
        degraded: verdict?.dataset.labelDegraded ?? 'Degraded',
        down: verdict?.dataset.labelDown ?? 'Major outage',
    };
    const timeoutText = rows[0].dataset.labelTimeout ?? 'timeout';

    let timer = null;

    const paint = (row, state, ms) => {
        row.dataset.state = state;

        const value = row.querySelector('[data-ms]');
        const fill = row.querySelector('[data-bar-fill]');

        if (value) {
            value.textContent = state === 'down' ? timeoutText : `${ms} ms`;
        }

        if (fill) {
            // A down check measured nothing, so its bar empties rather than showing a
            // length it never had. scaleX, not width: a transform does not relayout.
            fill.style.transform = `scaleX(${state === 'down' ? 0 : Math.min(1, ms / scale).toFixed(3)})`;
        }
    };

    const resolve = (row) => {
        const baseline = Number(row.dataset.baselineMs || 40);
        const roll = Math.random();
        const slow = () => jitter(Math.round(baseline * pick(SLOW_FACTOR)), 0.15);

        // A region that failed last cycle mostly recovers, and otherwise softens a
        // timeout into a slow response, which is the usual shape of coming back.
        if (row.dataset.state !== 'up') {
            return roll < P_RECOVER ? ['up', jitter(baseline)] : ['degraded', slow()];
        }

        if (roll < P_DOWN) {
            return ['down', 0];
        }

        return roll < P_DOWN + P_SLOW ? ['degraded', slow()] : ['up', jitter(baseline)];
    };

    const refreshVerdict = () => {
        const state = rollup(rows.map((r) => r.dataset.state));

        if (verdict) {
            verdict.dataset.state = state;
            verdict.textContent = labels[state];
        }

        if (verdictDot) {
            verdictDot.style.backgroundColor = `var(--app-${state})`;
        }

        // The newest segment of the history is today, still in progress, so it tracks
        // the verdict rather than sitting on a fixed colour.
        if (today) {
            today.dataset.state = state;
        }
    };

    const cycle = () => {
        // Every row goes in flight together: that is the visible difference between a
        // parallel fan-out and a rotation. Restarting the flash needs the class off
        // and on again across a reflow.
        if (!reduced) {
            rows.forEach((row) => {
                row.classList.remove('is-checking');
                void row.offsetWidth;
                row.classList.add('is-checking');
            });
        }

        let last = 0;

        rows.forEach((row) => {
            const [state, ms] = resolve(row);
            const delay = settleDelay(state, ms);

            last = Math.max(last, delay);

            window.setTimeout(() => {
                row.classList.remove('is-checking');
                paint(row, state, ms);
                refreshVerdict();
            }, delay);
        });

        // Measured from when this cycle finishes SETTLING, so a slow cycle cannot
        // overlap the next and leave two sets of results racing into the same rows.
        timer = window.setTimeout(cycle, last + Math.round(pick(GAP_MS)));
    };

    const stop = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    };

    new IntersectionObserver(
        (entries) =>
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    if (timer === null) {
                        cycle();
                    }
                } else {
                    stop();
                }
            }),
        { threshold: 0.1 },
    ).observe(panel);
}

function runSpotlight(card, reduced) {
    const tabs = Array.from(card.querySelectorAll('[data-scene-tab]'));
    const scenes = Array.from(card.querySelectorAll('[data-scene]'));

    if (scenes.length === 0) {
        return;
    }

    let index = 0;
    let timer = null;

    const show = (next) => {
        index = (next + scenes.length) % scenes.length;

        scenes.forEach((scene, i) => scene.setAttribute('aria-hidden', i === index ? 'false' : 'true'));
        tabs.forEach((tab, i) => tab.setAttribute('aria-selected', i === index ? 'true' : 'false'));

        // Replay the sweep each time the metric scene comes up, so the extraction is
        // something that happens rather than a highlight that was always there.
        const sweep = reduced ? null : scenes[index].querySelector('[data-sweep]');

        if (sweep) {
            sweep.classList.remove('is-sweeping');
            void sweep.offsetWidth;
            sweep.classList.add('is-sweeping');
        }
    };

    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            show(index + 1);
            schedule();
        }, SCENE_MS);
    };

    tabs.forEach((tab, i) =>
        tab.addEventListener('click', () => {
            show(i);
            // Clicking restarts the dwell, so a deliberate choice is not yanked away
            // half a second later.
            schedule();
        }),
    );

    new IntersectionObserver(
        (entries) =>
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    show(index);
                    schedule();
                } else {
                    window.clearTimeout(timer);
                }
            }),
        { threshold: 0.1 },
    ).observe(card);
}

export default function initHero() {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /*
     * Entrance. The hidden state lives behind `.js-motion`, set before first paint by
     * an inline snippet in the layout so the content is never shown and then hidden.
     * The hero is above the fold, so there is nothing to wait for: mark ready and let
     * the stagger play. No observer, no dead state.
     */
    if (!reduced) {
        document.documentElement.dataset.motionReady = '1';

        document.querySelectorAll('[data-enter]').forEach((el, i) => {
            if (!el.style.getPropertyValue('--enter-index')) {
                el.style.setProperty('--enter-index', String(i));
            }

            el.classList.add('is-in');
        });
    }

    const panel = document.querySelector('[data-monitor]');
    const spotlight = document.querySelector('[data-spotlight]');

    /*
     * Both run under reduced motion too, with the MOVEMENT removed rather than the
     * behaviour: no row flash, no ring, no sweep, and the CSS transitions are already
     * gated off. What remains is numbers and colours changing, which is information,
     * not decoration. The accessibility concern that `prefers-reduced-motion` exists
     * for is vestibular: movement, parallax, scaling. A latency reading updating in
     * place triggers none of that, and freezing it instead leaves someone who set
     * that preference looking at a screenshot of a monitoring product with no way to
     * tell it monitors anything.
     */
    if (panel) {
        runMonitor(panel, reduced);
    }

    if (spotlight) {
        runSpotlight(spotlight, reduced);
    }
}
