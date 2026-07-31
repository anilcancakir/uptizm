/**
 * The hero sequence: one incident, told in four acts, on a loop.
 *
 * Modelled on fluttersdk.com's hero workflow: an async sequence with explicit
 * `await delay(ms)` beats driving an `act` variable, whole stages swapped inside one
 * persistent frame, and text typed a character at a time so it reads as someone using
 * the product rather than a diagram of it.
 *
 * The four acts are the product's actual pipeline, which is why they carry four
 * separate claims without the hero feeling like four adverts stacked up:
 *
 *   1  setup     you point it at a URL and pick regions
 *   2  fan-out   every region is checked AT THE SAME MOMENT, results land in
 *                latency order
 *   3  triage    a metric out of the response body crosses its bound, and the AI
 *                drafts a cause from evidence it is allowed to cite
 *   4  delivery  it reaches whoever is on call, on whatever they are holding
 *
 * Honesty boundary: everything on this stage is an illustration against the
 * placeholder host `api.acme.com`. It reports on nothing. Every real claim on the
 * page is derived server-side in ShowLandingController.
 */

/** Region rows for act 2, with the data centre each one really came back from during
 *  live verification, so the geography is true even though the latency is an example. */
const REGIONS = [
    { label: 'EU West', colo: 'CDG', ms: 12 },
    { label: 'EU Central', colo: 'FRA', ms: 18 },
    { label: 'US East', colo: 'MIA', ms: 36 },
    { label: 'US West', colo: 'DFW', ms: 52 },
    { label: 'Asia-Pacific', colo: 'HKG', ms: 88 },
];

export const heroSequence = () => ({
    /** 1, 1.5, 2, 3, 4 — halves are transitions. */
    act: 0,

    /** Act 1 */
    url: '',
    pickedRegions: 0,
    submitting: false,

    /** Act 2: one entry per region, filled as results land. */
    results: [],

    /** Act 3 */
    metricValue: 0,
    metricBreached: false,
    showAi: false,

    /** Act 4 */
    delivered: [],

    verdict: 'up',
    running: false,

    /*
     * Generation token. Every new sequence bumps it, and anything already in flight
     * (a delay, a typing interval) checks it and bails when it is stale.
     *
     * Without this, jumping to an act while the loop was mid-typing left the old
     * `setInterval` still appending characters to the same property the jump had just
     * reset, and the field rendered the URL twice, spliced together. Any visitor
     * clicking a tab at the wrong moment hit it.
     */
    gen: 0,
    restartTimer: null,

    labels: {
        1: 'New monitor',
        1.5: 'Dispatching',
        2: 'Checks',
        3: 'Triage',
        4: 'Delivery',
    },

    init() {
        /*
         * ONE GATE: is the stage on screen. An earlier build also required the tab to
         * be visible and that created a dead state, because a page opened in a
         * background tab starts hidden and IntersectionObserver does not fire while
         * hidden, so nothing ever started it. Browsers already throttle background
         * timers to about once a minute.
         */
        new IntersectionObserver(
            (entries) =>
                entries.forEach((entry) => {
                    this.running = entry.isIntersecting;

                    if (this.running && this.act === 0) {
                        this.play();
                    }
                }),
            { threshold: 0.15 },
        ).observe(this.$el);
    },

    /** Reduced motion: no typing, no waiting, no transition acts. The visitor gets the
     *  finished state of the act that carries the most information, and the tabs still
     *  move between all four. */
    get reduced() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    },

    async play() {
        if (this.reduced) {
            this.showAct(3);

            return;
        }

        const g = ++this.gen;
        const alive = () => this.gen === g;

        while (alive()) {
            await this.act1(alive);
            if (!alive()) return;

            await this.act1Transition(alive);
            if (!alive()) return;

            await this.act2(alive);
            if (!alive()) return;

            await this.act3(alive);
            if (!alive()) return;

            await this.act4(alive);
        }
    },

    /** Jump straight to an act, fully resolved. Used by the tabs and by reduced
     *  motion, so no state is ever half-built. */
    showAct(n) {
        // Kill whatever is in flight, then resume the loop after a dwell so a click
        // never leaves the stage frozen for good.
        this.gen += 1;
        window.clearTimeout(this.restartTimer);
        this.restartTimer = window.setTimeout(() => this.play(), 7000);

        this.act = n;
        this.submitting = false;

        if (n === 1) {
            this.url = 'https://api.acme.com/health';
            this.pickedRegions = REGIONS.length;
        }

        if (n >= 2) {
            this.results = REGIONS.map((r) => ({ ...r, state: 'up', shown: r.ms }));
        }

        if (n === 3) {
            this.metricValue = 4812;
            this.metricBreached = true;
            this.showAi = true;
            this.results = REGIONS.map((r, i) => ({
                ...r,
                state: i >= 3 ? 'degraded' : 'up',
                shown: i >= 3 ? r.ms * 9 : r.ms,
            }));
            this.verdict = 'degraded';
        } else {
            this.metricBreached = false;
            this.showAi = false;
            this.verdict = 'up';
        }

        this.delivered = n === 4 ? ['web', 'ios', 'android'] : [];
    },

    async act1(alive = () => true) {
        this.act = 1;
        this.url = '';
        this.pickedRegions = 0;
        this.results = [];
        this.showAi = false;
        this.metricBreached = false;
        this.verdict = 'up';
        await this.delay(500);

        await this.type('url', 'https://api.acme.com/health', 45, alive);
        await this.delay(400);

        // Regions get picked one at a time, which is the moment the page earns the
        // phrase "checked from everywhere at once" that act 2 then shows.
        for (let i = 1; i <= REGIONS.length && alive(); i++) {
            this.pickedRegions = i;
            await this.delay(140);
        }

        await this.delay(700);
        this.submitting = true;
        await this.delay(450);
        this.submitting = false;
    },

    async act1Transition(alive = () => true) {
        this.act = 1.5;
        await this.delay(1400);
    },

    async act2(alive = () => true) {
        this.act = 2;
        // Every row goes in flight together: that is the whole point of the act.
        this.results = REGIONS.map((r) => ({ ...r, state: 'pending', shown: null }));
        await this.delay(350);

        // Results land in latency order, each on its own clock, because that is what a
        // parallel fan-out looks like from the outside.
        await Promise.all(
            this.results.map(
                (row, i) =>
                    new Promise((resolve) => {
                        setTimeout(() => {
                            this.results[i] = { ...row, state: 'up', shown: row.ms };
                            resolve();
                        }, 120 + row.ms * 14);
                    }),
            ),
        );

        this.verdict = 'up';
        await this.delay(2200);
    },

    async act3(alive = () => true) {
        this.act = 3;
        this.metricValue = 0;
        this.metricBreached = false;
        this.showAi = false;
        await this.delay(500);

        // The number climbs through its bound rather than appearing past it, so the
        // breach is something that happens.
        for (const v of [1180, 2260, 3410, 4812]) {
            if (!alive()) return;

            this.metricValue = v;
            await this.delay(520);
        }

        this.metricBreached = true;

        // Latency follows the queue, which is the correlation the AI then cites.
        [3, 4].forEach((i) => {
            this.results[i] = { ...this.results[i], state: 'degraded', shown: REGIONS[i].ms * 9 };
        });
        this.verdict = 'degraded';
        await this.delay(900);

        this.showAi = true;
        await this.delay(4200);
    },

    async act4(alive = () => true) {
        this.act = 4;
        this.delivered = [];
        await this.delay(400);

        for (const target of ['web', 'ios', 'android']) {
            if (!alive()) return;

            this.delivered.push(target);
            await this.delay(420);
        }

        await this.delay(3200);
    },

    delay(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    },

    /**
     * Types into a property one character at a time, and stops the moment its
     * generation is superseded. Writing `this[prop] +=` from a stale interval is what
     * produced a doubled URL.
     */
    type(prop, text, speed, alive = () => true) {
        return new Promise((resolve) => {
            this[prop] = '';
            let i = 0;

            const id = setInterval(() => {
                if (!alive()) {
                    clearInterval(id);

                    return resolve();
                }

                this[prop] += text.charAt(i);
                i += 1;

                if (i >= text.length) {
                    clearInterval(id);
                    resolve();
                }
            }, speed);
        });
    },
});
